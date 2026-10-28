<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Services\Crawl\CrawlRunProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One bounded crawl batch. It re-dispatches itself while work remains, so no
 * job holds a queue reservation for long (update quiescence stays reachable)
 * and a killed worker simply resumes from the persisted frontier.
 */
class ProcessCrawlRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(CrawlRunProcessor $processor): void
    {
        $next = $processor->processBatch($this->runId);
        if ($next === 'more') {
            self::dispatch($this->runId);
        } elseif ($next === 'later') {
            self::dispatch($this->runId)->delay(now()->addSeconds(15));
        }
    }

    public function failed(): void
    {
        CrawlRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'failure_code' => 'crawl_worker_failed', 'finished_at' => now()]);
    }
}
