<?php

namespace App\Services\Tasks;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The owner's own to-do list for a project. Links to a page or cluster must
 * belong to the same project. Every change is one audited change set.
 */
class ProjectTasks
{
    /** @param  array{title: string, notes?: ?string, status?: string, due_on?: ?string, page_id?: ?int, keyword_cluster_id?: ?int}  $data */
    public function create(Project $project, User $actor, array $data): ProjectTask
    {
        Gate::forUser($actor)->authorize('update', $project);
        $this->assertLinks($project, $data);

        return DB::transaction(function () use ($project, $actor, $data): ProjectTask {
            $task = ProjectTask::query()->create($this->withCompletion($data + ['status' => 'todo'], null) + [
                'project_id' => $project->id,
                'created_by_id' => $actor->id,
            ]);
            $this->record($project, $actor, 'task.created', $task->id, null, $this->snapshot($task));

            return $task;
        });
    }

    /** @param  array<string, mixed>  $data  validated task fields */
    public function update(Project $project, ProjectTask $task, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        $this->assertLinks($project, $data);
        DB::transaction(function () use ($project, $task, $actor, $data): void {
            $locked = ProjectTask::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($task->id);
            $before = $this->snapshot($locked);
            $locked->fill($this->withCompletion($data, $locked))->save();
            $after = $this->snapshot($locked->refresh());
            if ($before !== $after) {
                $this->record($project, $actor, 'task.updated', $locked->id, $before, $after);
            }
        });
    }

    public function delete(Project $project, ProjectTask $task, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        DB::transaction(function () use ($project, $task, $actor): void {
            $locked = ProjectTask::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($task->id);
            $this->record($project, $actor, 'task.deleted', $locked->id, $this->snapshot($locked), null);
            $locked->delete();
        });
    }

    /**
     * Done tasks remember when they were finished; reopening clears it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCompletion(array $data, ?ProjectTask $current): array
    {
        if (! array_key_exists('status', $data)) {
            return $data;
        }
        if ($data['status'] === 'done') {
            $data['completed_at'] = $current?->status === 'done' ? $current->completed_at : now();
        } else {
            $data['completed_at'] = null;
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function assertLinks(Project $project, array $data): void
    {
        foreach (['page_id' => 'pages', 'keyword_cluster_id' => 'keyword_clusters'] as $field => $table) {
            $id = $data[$field] ?? null;
            if ($id !== null && ! DB::table($table)->where('project_id', $project->id)->where('id', $id)->exists()) {
                throw ValidationException::withMessages([$field => 'این مورد در این پروژه پیدا نشد.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectTask $task): array
    {
        return [
            'title' => $task->title,
            'notes' => $task->notes,
            'status' => $task->status,
            'due_on' => $task->due_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'page_id' => $task->page_id,
            'keyword_cluster_id' => $task->keyword_cluster_id,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Project $project, User $actor, string $action, int $taskId, ?array $before, ?array $after): void
    {
        $set = ChangeSet::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'action' => $action,
            'changes' => [['task_id' => $taskId, 'before' => $before, 'after' => $after]],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'change_set_id' => $set->id, 'event' => $action, 'metadata' => ['task_id' => $taskId],
        ]);
    }
}
