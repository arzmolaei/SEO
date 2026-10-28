<?php

namespace App\Services\Crawl;

use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Project;
use App\Models\User;
use App\Services\Pages\RegisterExistingPage;
use Illuminate\Database\Eloquent\Builder;

/** Registers bounded batches through the existing page authorization/audit boundary. */
class CrawlPageLinker
{
    public function __construct(private readonly RegisterExistingPage $register) {}

    /** Returns false while a further linking batch is needed. */
    public function link(CrawlRun $run): bool
    {
        $query = $this->unlinked($run);
        if (! (clone $query)->exists()) {
            return true;
        }
        $actor = User::query()->find($run->created_by_id);
        if (! $actor instanceof User) {
            throw new CrawlRunException('crawl_actor_unavailable', 'کاربر آغازکننده خزش در دسترس نیست.');
        }
        $project = Project::query()->findOrFail($run->project_id);
        foreach ((clone $query)->orderBy('id')->limit(100)->get() as $row) {
            $page = $this->register->handle(
                $actor,
                $project,
                $row->final_url ?? $row->normalized_url,
                $row->title === null ? null : mb_substr($row->title, 0, 255),
                'crawler',
            );
            $row->forceFill(['page_id' => $page->id])->save();
        }

        return ! $this->unlinked($run)->exists();
    }

    /** @return Builder<CrawlUrl> */
    private function unlinked(CrawlRun $run): Builder
    {
        return CrawlUrl::query()->where('crawl_run_id', $run->id)
            ->where('state', 'fetched')->whereNull('page_id')->whereNull('error_code')
            ->whereBetween('status_code', [200, 299])
            ->where(fn (Builder $query) => $query->where('content_type', 'like', 'text/html%')
                ->orWhere('content_type', 'like', 'application/xhtml+xml%'));
    }
}
