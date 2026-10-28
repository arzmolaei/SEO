<?php

namespace App\Services\Crawl;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\CrawlRun;
use App\Models\User;

final class CrawlRunActivity
{
    /** @param array<string, mixed>|null $before */
    public static function record(CrawlRun $run, User $actor, string $event, ?array $before = null): void
    {
        $set = ChangeSet::query()->create([
            'workspace_id' => $run->project->workspace_id, 'project_id' => $run->project_id,
            'actor_id' => $actor->id, 'action' => 'crawl.'.$event,
            'changes' => [['crawl_run_id' => $run->id, 'before' => $before,
                'after' => $run->only(['status', 'pause_requested_at', 'cancel_requested_at'])]],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $run->project->workspace_id, 'project_id' => $run->project_id,
            'actor_id' => $actor->id, 'change_set_id' => $set->id,
            'event' => 'crawl.'.$event, 'metadata' => ['crawl_run_id' => $run->id],
        ]);
    }
}
