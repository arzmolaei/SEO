<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Jobs\RefreshProjectClusterReviews;
use App\Jobs\RefreshProjectMappingConflicts;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\MarkKeywordClusterReady;
use App\Services\Keywords\SetKeywordClusterLock;
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

    public function setLock(Request $request, Project $project, KeywordCluster $keywordCluster, SetKeywordClusterLock $setLock): RedirectResponse
    {
        $actor = $this->actor($request, $project);
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
}
