<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\DetectKeywordMappingConflicts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshProjectMappingConflicts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $actorId, public int $projectId) {}

    public function handle(DetectKeywordMappingConflicts $detector, ProjectPolicy $policy): void
    {
        $actor = User::query()->findOrFail($this->actorId);
        $project = Project::query()->findOrFail($this->projectId);
        if (! $policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        $detector->handle($actor, $project);
    }
}
