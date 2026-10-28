<?php

namespace App\Http\Controllers;

use App\Models\CrawlRun;
use App\Models\Project;
use App\Models\User;
use App\Services\Crawl\CrawlRunControls;
use App\Services\Crawl\CrawlRunException;
use App\Services\Crawl\StartCrawlRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CrawlController extends Controller
{
    private const RUN_COLUMNS = ['id', 'mode', 'start_url', 'status', 'discovered_count', 'fetched_count', 'failed_count', 'blocked_count', 'failure_code', 'pause_requested_at', 'cancel_requested_at', 'started_at', 'finished_at', 'created_at'];

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        $request->validate([
            'run' => ['nullable', 'integer', 'min:1'],
            'state' => ['nullable', Rule::in(['pending', 'fetched', 'failed', 'blocked', 'skipped'])],
            'status_class' => ['nullable', Rule::in(['2xx', '3xx', '4xx', '5xx'])],
            'indexable' => ['nullable', Rule::in(['yes', 'no', 'unknown'])],
            'sort' => ['nullable', Rule::in(['id', 'depth', 'status_code', 'response_ms', 'title'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $run = $request->filled('run')
            ? $project->crawlRuns()->findOrFail($request->integer('run'))
            : $project->crawlRuns()->latest('id')->first();
        $sort = $request->string('sort', 'id')->toString();
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        return Inertia::render('projects/crawls', [
            'canCrawl' => Gate::allows('crawl', $project),
            'activeRun' => fn (): ?array => $run?->only(self::RUN_COLUMNS),
            'runs' => fn () => $project->crawlRuns()->latest('id')->paginate(15, self::RUN_COLUMNS, 'runs_page')->withQueryString(),
            'urls' => function () use ($request, $run, $sort, $direction) {
                if ($run === null) {
                    return null;
                }
                $query = $run->urls();
                if ($request->filled('state')) {
                    $query->where('state', $request->string('state')->toString());
                }
                if ($request->filled('status_class')) {
                    $min = (int) substr($request->string('status_class')->toString(), 0, 1) * 100;
                    $query->whereBetween('status_code', [$min, $min + 99]);
                }
                if ($request->filled('indexable')) {
                    $value = $request->string('indexable')->toString();
                    $value === 'unknown' ? $query->whereNull('indexable') : $query->where('indexable', $value === 'yes');
                }

                return $query->orderBy($sort, $direction)->orderBy('id')
                    ->paginate(25, ['id', 'url', 'depth', 'state', 'status_code', 'final_url', 'redirect_count', 'content_type', 'response_ms', 'bytes', 'title', 'meta_description', 'robots', 'canonical', 'h1', 'h2', 'hreflang', 'schema_types', 'word_count', 'images', 'images_missing_alt', 'internal_links', 'external_links', 'indexable', 'error_code', 'page_id', 'attempts', 'fetched_at'])
                    ->withQueryString();
            },
            'filters' => $request->only(['state', 'status_class', 'indexable', 'sort', 'direction']),
            'limits' => ['max_urls' => (int) config('crawler.max_urls_limit'), 'max_depth' => (int) config('crawler.max_depth_limit')],
        ]);
    }

    public function store(Request $request, Project $project, StartCrawlRun $start): RedirectResponse
    {
        Gate::authorize('crawl', $project);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['site', 'sitemap', 'list'])],
            'start_url' => ['required', 'string', 'max:8192'],
            'urls' => ['required_if:mode,list', 'nullable', 'string', 'max:1048576'],
            'include' => ['nullable', 'string', 'max:25600'],
            'exclude' => ['nullable', 'string', 'max:25600'],
            'include_www' => ['sometimes', 'boolean'],
            'max_depth' => ['sometimes', 'integer', 'min:0', 'max:'.config('crawler.max_depth_limit')],
            'max_urls' => ['sometimes', 'integer', 'min:1', 'max:'.config('crawler.max_urls_limit')],
        ]);
        foreach (['include', 'exclude'] as $field) {
            $prefixes = $this->lines($data[$field] ?? '');
            if (count($prefixes) > 50 || array_any($prefixes, static fn (string $prefix): bool => ! str_starts_with(trim($prefix), '/') || strlen(trim($prefix)) > 512)) {
                throw ValidationException::withMessages([$field => 'حداکثر ۵۰ پیشوند مسیر معتبر با / وارد کنید.']);
            }
        }
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $run = $start->handle($project, $actor, [
                'mode' => $request->string('mode')->toString(),
                'start_url' => $request->string('start_url')->toString(),
                'urls' => $this->lines($data['urls'] ?? ''),
                'include' => $this->lines($data['include'] ?? ''),
                'exclude' => $this->lines($data['exclude'] ?? ''),
                'include_www' => $request->boolean('include_www'),
                'max_depth' => $request->integer('max_depth', 10),
                'max_urls' => $request->integer('max_urls', 1000),
            ]);
        } catch (CrawlRunException $exception) {
            throw ValidationException::withMessages([$exception->codeName === 'crawl_invalid_start_url' ? 'start_url' : 'crawl' => $exception->getMessage()]);
        }

        return to_route('projects.crawls.index', ['project' => $project, 'run' => $run->id]);
    }

    public function control(Request $request, Project $project, CrawlRun $crawlRun, string $action, CrawlRunControls $controls): RedirectResponse
    {
        Gate::authorize('crawl', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            match ($action) {
                'pause' => $controls->pause($crawlRun, $actor),
                'resume' => $controls->resume($crawlRun, $actor),
                'cancel' => $controls->cancel($crawlRun, $actor),
                default => abort(404),
            };
        } catch (CrawlRunException $exception) {
            throw ValidationException::withMessages(['crawl' => $exception->getMessage()]);
        }

        return to_route('projects.crawls.index', ['project' => $project, 'run' => $crawlRun->id]);
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(preg_split('/\r\n|\n|\r/', $text) ?: [], static fn (string $line): bool => trim($line) !== ''));
    }
}
