<?php

namespace App\Services\Pages;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class PageOperations
{
    public function __construct(private ProjectPolicy $policy) {}

    public function authorize(User $actor, Project $project): void
    {
        if (! $this->policy->update($actor, $project)) {
            throw new AuthorizationException;
        }
        Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
    }

    public function title(?string $title, bool $required = false): ?string
    {
        $title = $title === null ? null : trim($title);
        if (($required && ($title === null || $title === '')) || ($title !== null && mb_strlen($title) > 255)) {
            throw new InvalidArgumentException('Page title must contain 1 to 255 characters.');
        }

        return $title === '' ? null : $title;
    }

    /** @param array<string,mixed>|null $before */
    public function record(User $actor, Project $project, Page $page, ?array $before, string $event, string $source = 'user'): void
    {
        $set = ChangeSet::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id, 'action' => $event,
            'changes' => [['page_id' => $page->id, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($page->getAttributes(), JSON_THROW_ON_ERROR)]]]);
        ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id, 'change_set_id' => $set->id, 'event' => $event, 'metadata' => ['page_id' => $page->id, 'source' => $source]]);
    }
}
