<?php

namespace App\Services\Crawl;

use App\Domain\Crawl\CrawlTargetPolicy;
use App\Domain\Pages\NormalizePageUrl;
use App\Jobs\ProcessCrawlRun;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Starts one crawl for a project. At most one run per project is active;
 * the scope (allowed hosts) is frozen on the run when it starts.
 */
class StartCrawlRun
{
    /**
     * @param  array{mode: string, start_url: string, urls?: list<string>, include?: list<string>, exclude?: list<string>, max_depth?: int, max_urls?: int, include_www?: bool}  $input
     */
    public function handle(Project $project, User $user, array $input): CrawlRun
    {
        Gate::forUser($user)->authorize('crawl', $project);
        try {
            $start = (new NormalizePageUrl)->normalize($input['start_url']);
        } catch (InvalidArgumentException) {
            throw new CrawlRunException('crawl_invalid_start_url', 'نشانی شروع خزش معتبر نیست.');
        }
        $authority = CrawlTargetPolicy::authority($start);
        $hosts = [$authority];
        if ($input['include_www'] ?? false) {
            $hosts[] = str_starts_with($authority, 'www.') ? substr($authority, 4) : 'www.'.$authority;
        }

        $settings = [
            'include' => self::prefixes($input['include'] ?? []),
            'exclude' => self::prefixes($input['exclude'] ?? []),
            'max_depth' => max(0, min((int) config('crawler.max_depth_limit'), (int) ($input['max_depth'] ?? 10))),
            'max_urls' => max(1, min((int) config('crawler.max_urls_limit'), (int) ($input['max_urls'] ?? 1000))),
            'delay_ms' => (int) config('crawler.default_delay_ms'),
            'include_www' => (bool) ($input['include_www'] ?? false),
        ];
        $policy = new CrawlTargetPolicy($hosts);

        $run = DB::transaction(function () use ($project, $user, $input, $start, $hosts, $settings, $policy): CrawlRun {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (CrawlRun::query()->where('project_id', $project->id)->whereIn('status', CrawlRun::ACTIVE_STATUSES)->exists()) {
                throw new CrawlRunException('crawl_already_active', 'یک خزش دیگر برای این پروژه در جریان است.');
            }

            $run = CrawlRun::query()->create([
                'project_id' => $project->id,
                'created_by_id' => $user->id,
                'mode' => $input['mode'],
                'start_url' => $input['start_url'],
                'normalized_start_url' => $start,
                'allowed_hosts' => $hosts,
                'settings' => $settings,
                'status' => 'queued',
            ]);

            $seeds = $input['mode'] === 'list' ? ($input['urls'] ?? []) : ($input['mode'] === 'site' ? [$start] : []);
            $rows = [];
            foreach (array_slice($seeds, 0, $settings['max_urls']) as $seed) {
                try {
                    $normalized = (new NormalizePageUrl)->normalize(trim($seed));
                } catch (InvalidArgumentException) {
                    continue;
                }
                $rows[hash('sha256', $normalized)] = [
                    'crawl_run_id' => $run->id,
                    'url' => trim($seed),
                    'normalized_url' => $normalized,
                    'url_hash' => hash('sha256', $normalized),
                    'depth' => 0,
                    'source' => $input['mode'] === 'list' ? 'list' : 'start',
                    'state' => $policy->isInScope($normalized) ? 'pending' : 'skipped',
                    'error_code' => $policy->isInScope($normalized) ? null : 'out_of_scope',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows === [] && $input['mode'] !== 'sitemap') {
                throw new CrawlRunException('crawl_no_urls', 'هیچ نشانی معتبری برای خزش وجود ندارد.');
            }
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                CrawlUrl::query()->insert($chunk);
            }
            $run->forceFill(['discovered_count' => count($rows)])->save();

            CrawlRunActivity::record($run, $user, 'started');
            ProcessCrawlRun::dispatch($run->id)->beforeCommit();

            return $run;
        });

        return $run;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function prefixes(array $values): array
    {
        $prefixes = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '' && str_starts_with($value, '/') && strlen($value) <= 512) {
                $prefixes[] = $value;
            }
        }

        return array_values(array_unique(array_slice($prefixes, 0, 50)));
    }
}
