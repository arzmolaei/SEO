<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Jobs\RefreshProjectClusterReviews;
use App\Jobs\RefreshProjectMappingConflicts;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\MarkKeywordClusterReady;
use App\Services\Keywords\MergeKeywordClusters;
use App\Services\Keywords\MoveKeywordBetweenClusters;
use App\Services\Keywords\SetKeywordClusterLock;
use App\Services\Keywords\SplitKeywordCluster;
use App\Services\Keywords\UpdateKeywordClusterPlanning;
use App\Services\Pages\CreatePlannedPage;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KeywordIntelligenceController extends Controller
{
    public function __construct(private ProjectPolicy $policy) {}

    public function storeCluster(Request $request, Project $project, CreateKeywordCluster $create): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'primary_keyword_id' => ['required', 'integer', 'min:1'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'intent' => ['required', Rule::enum(KeywordIntent::class)],
        ]);
        $primary = Keyword::query()->findOrFail((int) $validated['primary_keyword_id']);
        $memberIds = array_values(array_map('intval', (array) $validated['member_ids']));

        try {
            $create->handle($actor, $project, (string) $validated['name'], $primary, $memberIds, KeywordIntent::from((string) $validated['intent']));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function updateCluster(Request $request, Project $project, KeywordCluster $keywordCluster, UpdateKeywordClusterPlanning $update): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate([
            'review_state' => ['sometimes', Rule::enum(ClusterReviewState::class)],
            'business_relevance' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
        ]);
        $changes = [];
        if (array_key_exists('review_state', $validated)) {
            $changes['review_state'] = ClusterReviewState::from((string) $validated['review_state']);
        }
        if (array_key_exists('business_relevance', $validated)) {
            $changes['business_relevance'] = $validated['business_relevance'] === null ? null : (int) $validated['business_relevance'];
        }

        try {
            $update->handle($actor, $project, $keywordCluster, $changes);
        } catch (InvalidArgumentException|DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function searchClusters(Request $request, Project $project): JsonResponse
    {
        $this->actor($request, $project);
        $validated = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['q'] ?? ''));
        $clusters = KeywordCluster::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->with('primaryKeyword:id,project_id,display_text,normalized_text')
            ->withCount('members')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('primaryKeyword', function (Builder $keywordQuery) use ($search): void {
                            $keywordQuery->where('display_text', 'like', '%'.$search.'%')
                                ->orWhere('normalized_text', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(function (KeywordCluster $cluster): array {
                $primary = $cluster->primaryKeyword;

                return [
                    'id' => $cluster->id,
                    'name' => $cluster->name,
                    'primary_keyword_id' => $cluster->primary_keyword_id,
                    'primary_keyword' => $primary instanceof Keyword ? $primary->display_text : null,
                    'member_count' => (int) $cluster->getAttribute('members_count'),
                    'is_locked' => $cluster->is_locked,
                ];
            })->values();

        return response()->json(['data' => $clusters]);
    }

    public function searchClusterMembers(Request $request, Project $project, KeywordCluster $keywordCluster): JsonResponse
    {
        $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['q'] ?? ''));
        $members = KeywordClusterMember::query()
            ->where('project_id', $project->id)
            ->where('keyword_cluster_id', $keywordCluster->id)
            ->with('keyword:id,project_id,display_text,normalized_text')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('keyword', function (Builder $keywordQuery) use ($search): void {
                    $keywordQuery->where('display_text', 'like', '%'.$search.'%')
                        ->orWhere('normalized_text', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(function (KeywordClusterMember $member) use ($keywordCluster): array {
                $keyword = $member->keyword;

                return [
                    'id' => $member->id,
                    'keyword_id' => $member->keyword_id,
                    'keyword_cluster_id' => $member->keyword_cluster_id,
                    'display_text' => $keyword instanceof Keyword ? $keyword->display_text : null,
                    'is_primary' => $member->keyword_id === $keywordCluster->primary_keyword_id,
                ];
            })->values();

        return response()->json(['data' => $members]);
    }

    public function mergeClusters(Request $request, Project $project, KeywordCluster $keywordCluster, MergeKeywordClusters $merge): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate([
            'source_cluster_ids' => ['required', 'array', 'min:1', 'max:20'],
            'source_cluster_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $sourceIds = array_values(array_map('intval', (array) $validated['source_cluster_ids']));
        sort($sourceIds, SORT_NUMERIC);
        $sourceCount = KeywordCluster::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $sourceIds)
            ->count();
        if ($sourceCount !== count($sourceIds)) {
            throw new AuthorizationException;
        }

        try {
            $merge->handle($actor, $project, $keywordCluster, $sourceIds);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function moveClusterMember(Request $request, Project $project, KeywordCluster $keywordCluster, Keyword $keyword, MoveKeywordBetweenClusters $move): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        if ($keyword->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if (! KeywordClusterMember::query()
            ->where('project_id', $project->id)
            ->where('keyword_cluster_id', $keywordCluster->id)
            ->where('keyword_id', $keyword->id)
            ->exists()) {
            throw new ConflictHttpException('Keyword membership changed.');
        }

        $validated = $request->validate([
            'target_cluster_id' => ['required', 'integer', 'min:1'],
            'replacement_primary_keyword_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $target = KeywordCluster::query()
            ->where('project_id', $project->id)
            ->find((int) $validated['target_cluster_id']);
        if (! $target instanceof KeywordCluster) {
            throw new AuthorizationException;
        }
        $replacement = null;
        if (($validated['replacement_primary_keyword_id'] ?? null) !== null) {
            $replacement = Keyword::query()
                ->where('project_id', $project->id)
                ->find((int) $validated['replacement_primary_keyword_id']);
            if (! $replacement instanceof Keyword) {
                throw new AuthorizationException;
            }
        }

        $move->handle($actor, $project, $keyword, $target, $replacement);

        return to_route('projects.keywords.index', $project);
    }

    public function splitCluster(Request $request, Project $project, KeywordCluster $keywordCluster, SplitKeywordCluster $split): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1', 'max:500'],
            'member_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'name' => ['required', 'string', 'max:255'],
            'primary_keyword_id' => ['required', 'integer', 'min:1'],
            'replacement_primary_keyword_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $memberIds = array_values(array_map('intval', (array) $validated['member_ids']));
        $primary = Keyword::query()
            ->where('project_id', $project->id)
            ->find((int) $validated['primary_keyword_id']);
        if (! $primary instanceof Keyword) {
            throw new AuthorizationException;
        }
        $replacement = null;
        if (($validated['replacement_primary_keyword_id'] ?? null) !== null) {
            $replacement = Keyword::query()
                ->where('project_id', $project->id)
                ->find((int) $validated['replacement_primary_keyword_id']);
            if (! $replacement instanceof Keyword) {
                throw new AuthorizationException;
            }
        }

        try {
            $split->handle($actor, $project, $keywordCluster, $memberIds, (string) $validated['name'], $primary, $replacement);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function setLock(Request $request, Project $project, KeywordCluster $keywordCluster, SetKeywordClusterLock $setLock): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate(['locked' => ['required', 'boolean']]);
        $setLock->handle($actor, $project, $keywordCluster, (bool) $validated['locked']);

        return to_route('projects.keywords.index', $project);
    }

    public function storePage(Request $request, Project $project, CreatePlannedPage $create): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);

        try {
            $create->handle($actor, $project, (string) $validated['title']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function searchPages(Request $request, Project $project): JsonResponse
    {
        $this->actor($request, $project);
        $validated = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['q'] ?? ''));
        $pages = Page::query()
            ->where('project_id', $project->id)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('title', 'like', '%'.$search.'%')
                        ->orWhere('url', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'kind' => $page->kind->value,
                'page_type' => $page->page_type,
                'url' => $page->url,
            ])->values();

        return response()->json(['data' => $pages]);
    }

    public function mapTarget(Request $request, Project $project, KeywordCluster $keywordCluster, MapClusterToTarget $map): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(ClusterTargetType::class)],
            'page_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $page = array_key_exists('page_id', $validated) && $validated['page_id'] !== null
            ? Page::query()->findOrFail((int) $validated['page_id'])
            : null;

        try {
            $map->handle($actor, $project, $keywordCluster, ClusterTargetType::from((string) $validated['type']), $page);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function markReady(Request $request, Project $project, KeywordCluster $keywordCluster, MarkKeywordClusterReady $ready): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $this->assertClusterProject($keywordCluster, $project);

        try {
            $ready->handle($actor, $project, $keywordCluster);
        } catch (DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('projects.keywords.index', $project);
    }

    public function refreshClusterSuggestions(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        RefreshProjectClusterReviews::dispatch($actor->id, $project->id);

        return to_route('projects.keywords.index', $project);
    }

    public function refreshMappingConflicts(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        RefreshProjectMappingConflicts::dispatch($actor->id, $project->id);

        return to_route('projects.keywords.index', $project);
    }

    private function actor(Request $request, Project $project): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function assertClusterProject(KeywordCluster $cluster, Project $project): void
    {
        if ($cluster->project_id !== $project->id) {
            throw new AuthorizationException;
        }
    }
}
