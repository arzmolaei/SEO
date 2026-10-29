<?php

namespace App\Services\SearchConsole;

use App\Domain\Keywords\PersianKeywordNormalizer;
use App\Domain\Keywords\StreamingCsvReader;
use App\Domain\Pages\NormalizePageUrl;
use App\Domain\SearchConsole\SearchConsoleCsv;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Project;
use App\Models\SearchConsoleImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Imports one Search Console Performance export (Queries.csv or Pages.csv)
 * for a period the owner states. The file is read once, bounded, and stored
 * in one transaction; nothing is sent anywhere.
 */
class ImportSearchConsoleCsv
{
    public const MAX_ROWS = 50000;

    public function handle(Project $project, User $actor, string $path, string $filename, CarbonImmutable $start, CarbonImmutable $end): SearchConsoleImport
    {
        Gate::forUser($actor)->authorize('update', $project);
        $reader = new StreamingCsvReader;
        $format = SearchConsoleCsv::detect($reader->headers($path));
        if ($format === null) {
            throw ValidationException::withMessages(['file' => 'این فایل خروجی «پرسش‌ها» یا «صفحه‌ها»ی سرچ کنسول نیست. فایل Queries.csv یا Pages.csv را از گزارش Performance بارگذاری کنید.']);
        }

        $normalizer = new PersianKeywordNormalizer;
        $urls = new NormalizePageUrl;
        $rows = [];
        $seen = [];
        $skipped = 0;
        foreach ($reader->rows($path) as $row) {
            $parsed = SearchConsoleCsv::parse($row, $format['columns']);
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            $normalized = $format['kind'] === 'pages' ? $this->pageKey($urls, $parsed['key']) : $normalizer->normalize($parsed['key']);
            $hash = hash('sha256', $normalized);
            if (isset($seen[$hash])) {
                $skipped++;

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'این فایل بیش از '.number_format(self::MAX_ROWS).' ردیف دارد.']);
            }
            $seen[$hash] = true;
            $rows[] = [
                'project_id' => $project->id,
                'key_text' => $parsed['key'],
                'normalized_key' => $normalized,
                'key_hash' => $hash,
                'clicks' => $parsed['clicks'],
                'impressions' => $parsed['impressions'],
                'position' => $parsed['position'],
            ];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'در این فایل ردیف قابل استفاده‌ای پیدا نشد.']);
        }

        return DB::transaction(function () use ($project, $actor, $filename, $start, $end, $format, $rows, $skipped): SearchConsoleImport {
            $import = SearchConsoleImport::query()->create([
                'project_id' => $project->id,
                'created_by_id' => $actor->id,
                'kind' => $format['kind'],
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'original_filename' => mb_substr($filename, 0, 255),
                'row_count' => count($rows),
                'skipped_count' => $skipped,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('search_console_rows')->insert(array_map(
                    fn (array $row): array => $row + ['search_console_import_id' => $import->id],
                    $chunk,
                ));
            }
            $this->record($project, $actor, 'search_console.imported', $import, [
                'kind' => $import->kind, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'rows' => count($rows), 'skipped' => $skipped, 'filename' => $import->original_filename,
            ]);

            return $import;
        });
    }

    public function delete(Project $project, SearchConsoleImport $import, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        DB::transaction(function () use ($project, $import, $actor): void {
            $locked = SearchConsoleImport::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($import->id);
            // The original CSV can be imported again; the record keeps what was removed.
            $this->record($project, $actor, 'search_console.deleted', $locked, [
                'kind' => $locked->kind, 'period_start' => $locked->period_start->toDateString(),
                'period_end' => $locked->period_end->toDateString(), 'rows' => $locked->row_count,
                'filename' => $locked->original_filename,
            ]);
            $locked->delete();
        });
    }

    /** Page addresses are compared by their normalized URL, like the pages workspace. */
    private function pageKey(NormalizePageUrl $urls, string $url): string
    {
        try {
            return $urls->normalize($url);
        } catch (InvalidArgumentException) {
            return trim($url);
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function record(Project $project, User $actor, string $action, SearchConsoleImport $import, array $summary): void
    {
        $set = ChangeSet::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'action' => $action,
            'changes' => [['search_console_import_id' => $import->id] + $summary],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'change_set_id' => $set->id, 'event' => $action, 'metadata' => ['search_console_import_id' => $import->id],
        ]);
    }
}
