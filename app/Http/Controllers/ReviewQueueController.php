<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
use App\Jobs\RefreshProjectIntentReviews;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Review\ResolveClusterReview;
use App\Services\Review\ResolveIntentReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReviewQueueController extends Controller
{
    public function __construct(private ProjectPolicy $policy) {}

    public function index(Request $request, Project $project): Response
    {
        $actor = $this->actor($request, $project, false);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $reviews = ReviewItem::query()->where('project_id', $project->id)->whereIn('type', [ReviewItemType::Intent, ReviewItemType::Cluster])
            ->where('status', 'pending')->orderBy('id')->paginate(25)->through(fn (ReviewItem $item): array => [
                'id' => $item->id, 'type' => $item->type->value, 'keyword_id' => $item->keyword_id, 'fingerprint' => $item->fingerprint, 'suggestion' => array_diff_key($item->suggestion, ['candidate' => true]),
            ]);

        return Inertia::render('projects/review-queue', [
            'reviews' => $reviews,
            'canManage' => $this->policy->manageKeywords($actor, $project),
            'intents' => array_map(fn (KeywordIntent $intent): string => $intent->value, KeywordIntent::cases()),
        ]);
    }

    public function refresh(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->actor($request, $project, true);
        RefreshProjectIntentReviews::dispatch($actor->id, $project->id);

        return to_route('projects.reviews.index', $project);
    }

    public function resolve(Request $request, Project $project, ReviewItem $reviewItem, ResolveIntentReview $resolve, ResolveClusterReview $resolveCluster): RedirectResponse
    {
        $actor = $this->actor($request, $project, true);
        if ($reviewItem->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'decision' => ['required', Rule::in($reviewItem->type === ReviewItemType::Cluster ? ['accept', 'skip'] : ['accept', 'change', 'skip'])],
            'intent' => ['required_if:decision,change', 'nullable', Rule::enum(KeywordIntent::class)],
        ]);
        if ($reviewItem->type === ReviewItemType::Cluster) {
            $resolveCluster->handle($actor, $project, $reviewItem, $validated['fingerprint'], $validated['decision']);
        } else {
            $resolve->handle($actor, $project, $reviewItem, $validated['fingerprint'], $validated['decision'],
                isset($validated['intent']) ? KeywordIntent::from($validated['intent']) : null);
        }

        return to_route('projects.reviews.index', $project);
    }

    private function actor(Request $request, Project $project, bool $manage): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! ($manage ? $this->policy->manageKeywords($actor, $project) : $this->policy->view($actor, $project))) {
            throw new AuthorizationException;
        }

        return $actor;
    }
}
