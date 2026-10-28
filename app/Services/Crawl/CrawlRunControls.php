<?php

namespace App\Services\Crawl;

use App\Jobs\ProcessCrawlRun;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Pause, resume and cancel; each takes effect at a URL boundary. */
class CrawlRunControls
{
    public function pause(CrawlRun $run, User $actor): CrawlRun
    {
        return $this->locked($run, $actor, 'pause_requested', function (CrawlRun $run): void {
            if (! in_array($run->status, ['queued', 'running'], true)) {
                throw $this->invalid();
            }
            $run->forceFill(['pause_requested_at' => now()])->save();
        });
    }

    public function resume(CrawlRun $run, User $actor): CrawlRun
    {
        $run = $this->locked($run, $actor, 'resumed', function (CrawlRun $run): void {
            if ($run->status !== 'paused') {
                throw $this->invalid();
            }
            $run->forceFill(['status' => 'queued', 'pause_requested_at' => null])->save();
            ProcessCrawlRun::dispatch($run->id)->beforeCommit();
        });

        return $run;
    }

    public function cancel(CrawlRun $run, User $actor): CrawlRun
    {
        return $this->locked($run, $actor, 'cancel_requested', function (CrawlRun $run): void {
            if (! $run->isActive()) {
                throw $this->invalid();
            }
            $run->forceFill(['cancel_requested_at' => now()])->save();
            if ($run->status !== 'running') {
                // No worker owns a queued or paused run: finish it now.
                self::finishCancelled($run);
            }
        });
    }

    public static function finishCancelled(CrawlRun $run): void
    {
        CrawlUrl::query()->where('crawl_run_id', $run->id)->where('state', 'pending')
            ->update(['state' => 'skipped', 'error_code' => 'cancelled', 'claim_token' => null, 'updated_at' => now()]);
        $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
    }

    /** @param  callable(CrawlRun): void  $change */
    private function locked(CrawlRun $run, User $actor, string $event, callable $change): CrawlRun
    {
        Gate::forUser($actor)->authorize('crawl', $run->project);

        return DB::transaction(function () use ($run, $actor, $event, $change): CrawlRun {
            $locked = CrawlRun::query()->where('project_id', $run->project_id)->lockForUpdate()->findOrFail($run->id);
            $before = $locked->only(['status', 'pause_requested_at', 'cancel_requested_at']);
            $change($locked);
            CrawlRunActivity::record($locked, $actor, $event, $before);

            return $locked;
        });
    }

    private function invalid(): CrawlRunException
    {
        return new CrawlRunException('crawl_invalid_state', 'این عملیات در وضعیت فعلی خزش ممکن نیست.');
    }
}
