<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\Crawl\CrawlRunException;
use App\Services\Crawl\StartCrawlRun;
use App\Services\Pages\PageLiveFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Own-site page check: reuses the existing crawler in URL-list mode for the
 * project's own page addresses only (bounded), and lets the owner copy the
 * live title/description/H1 into fields they have not written yet.
 */
class PageLiveCheckController extends Controller
{
    public const MAX_PAGES = 500;

    public function check(Request $request, Project $project, StartCrawlRun $start): RedirectResponse
    {
        Gate::authorize('crawl', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $pages = Page::query()->where('project_id', $project->id)->whereNotNull('url')
            ->orderBy('id')->limit(self::MAX_PAGES)->get(['id', 'url']);
        $urls = array_values(array_filter($pages->map(fn (Page $page): ?string => $page->url)->all()));
        if ($urls === []) {
            throw ValidationException::withMessages(['check' => 'هنوز صفحه‌ای با آدرس ندارید که بررسی شود.']);
        }
        try {
            $start->handle($project, $actor, [
                'mode' => 'list',
                'start_url' => $urls[0],
                'urls' => $urls,
                'include_www' => true,
                'max_depth' => 0,
                'max_urls' => self::MAX_PAGES,
            ]);
        } catch (CrawlRunException $exception) {
            throw ValidationException::withMessages(['check' => $exception->getMessage()]);
        }

        return to_route('projects.pages.index', $project);
    }

    public function adopt(Request $request, Project $project, Page $page, PageLiveFacts $facts): RedirectResponse
    {
        Gate::authorize('update', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        DB::transaction(function () use ($project, $page, $facts, $actor): void {
            $locked = Page::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($page->id);
            $live = $facts->for($project, collect([$locked]))[$locked->id] ?? null;
            if ($live === null) {
                throw ValidationException::withMessages(['adopt' => 'این صفحه هنوز در سایت بررسی نشده است.']);
            }
            $candidates = [
                'seo_title' => self::fit($live['title'], 255),
                'meta_description' => self::fit($live['meta_description'], 500),
                'h1' => self::fit($live['h1'][0] ?? null, 255),
            ];
            $before = $locked->only(array_keys($candidates));
            $after = $before;
            foreach ($candidates as $field => $value) {
                // Only fill what the owner has not written; never overwrite.
                if ($value !== null && trim((string) ($before[$field] ?? '')) === '') {
                    $after[$field] = $value;
                }
            }
            if ($before === $after) {
                return;
            }
            $locked->fill($after)->save();
            $set = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'action' => 'page.live_values_adopted',
                'changes' => [['page_id' => $locked->id, 'before' => $before, 'after' => $after]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $set->id, 'event' => 'page.live_values_adopted', 'metadata' => ['page_id' => $locked->id],
            ]);
        });

        return to_route('projects.pages.show', ['project' => $project, 'page' => $page]);
    }

    private static function fit(?string $value, int $max): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
