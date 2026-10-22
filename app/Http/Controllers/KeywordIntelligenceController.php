<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\MarkKeywordClusterReady;
use App\Services\Keywords\UpdateKeywordClusterPlanning;
use App\Services\Pages\CreatePlannedPage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $memberIds = array_map('intval', $validated['member_ids']);
        $create->handle(
            $actor,
            $project,
            $validated['name'],
            $primary,
            $memberIds,
            KeywordIntent::from($validated['intent']),
        );

        return to_route('projects.keywords.index', $project);
    }

    public function updateCluster(
        Request $request,
        Project $project,
        KeywordCluster $keywordCluster,
        UpdateKeywordClusterPlanning $update,
    ): RedirectResponse {
        $actor = $this->actor($request, $project);
        $validated = $request->validate([
            'review_state' => ['sometimes', Rule::enum(ClusterReviewState::class)],
            'business_relevance' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
        ]);
        $changes = [];
        if (array_key_exists('review_state', $validated)) {
            $changes['review_state'] = ClusterReviewState::from($validated['review_state']);
        }
        if (array_key_exists('business_relevance', $validated)) {
            $changes['business_relevance'] = $validated['business_relevance'] === null ? null : (int) $validated['business_relevance'];
        }
        $update->handle($actor, $project, $keywordCluster, $changes);

        return to_route('projects.keywords.index', $project);
    }

    public function storePage(Request $request, Project $project, CreatePlannedPage $create): RedirectResponse
    {
        $actor = $this->actor($request, $project);
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $create->handle($actor, $project, $validated['title']);

        return to_route('projects.keywords.index', $project);
    }

    public function mapTarget(
        Request $request,
        Project $project,
        KeywordCluster $keywordCluster,
        MapClusterToTarget $map,
    ): RedirectResponse {
        $actor = $this->actor($request, $project);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(ClusterTargetType::class)],
            'page_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $page = isset($validated['page_id']) ? Page::query()->findOrFail((int) $validated['page_id']) : null;
        $map->handle($actor, $project, $keywordCluster, ClusterTargetType::from($validated['type']), $page);

        return to_route('projects.keywords.index', $project);
    }

    public function markReady(
        Request $request,
        Project $project,
        KeywordCluster $keywordCluster,
        MarkKeywordClusterReady $ready,
    ): RedirectResponse {
        $actor = $this->actor($request, $project);
        $ready->handle($actor, $project, $keywordCluster);

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
