<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Review\UpsertIntentReviewItems;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshProjectIntentReviews implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $actorId, public int $projectId, public int $afterId = 0) {}

    public function handle(UpsertIntentReviewItems $upsert, ProjectPolicy $policy): void
    {
        $actor = User::query()->findOrFail($this->actorId);
        $project = Project::query()->findOrFail($this->projectId);
        if (! $policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }
        $keywords = Keyword::query()->where('project_id', $project->id)->where('id', '>', $this->afterId)
            ->orderBy('id')->limit(100)->get();
        foreach ($keywords as $keyword) {
            $upsert->handle($actor, $project, $keyword);
        }
        if ($keywords->count() === 100) {
            self::dispatch($actor->id, $project->id, $keywords->last()->id);
        }
    }
}
