<?php

namespace App\Http\Controllers;

use App\Domain\SearchConsole\SearchConsoleCsv;
use App\Models\Project;
use App\Models\SearchConsoleImport;
use App\Models\User;
use App\Services\SearchConsole\ImportSearchConsoleCsv;
use App\Services\SearchConsole\SearchConsoleInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SearchConsoleController extends Controller
{
    private const SORTS = ['clicks', 'impressions', 'position'];

    public function index(Request $request, Project $project, SearchConsoleInsights $insights): Response
    {
        Gate::authorize('view', $project);
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(SearchConsoleCsv::KINDS)],
            'import' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ]);
        $kind = $filters['kind'] ?? 'queries';
        $sort = $filters['sort'] ?? 'clicks';
        $selected = isset($filters['import'])
            ? SearchConsoleImport::query()->where('project_id', $project->id)->where('kind', $kind)->find((int) $filters['import'])
            : $insights->latest($project, $kind);

        $rows = $selected === null ? null : DB::table('search_console_rows')
            ->where('search_console_import_id', $selected->id)
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $query->where('key_text', 'like', '%'.addcslashes($term, '%_\\').'%');
            })
            ->when($sort === 'position', fn ($query) => $query->orderBy('position'), fn ($query) => $query->orderByDesc($sort))
            ->orderBy('id')
            ->paginate(25, ['key_text', 'normalized_key', 'clicks', 'impressions', 'position'])->withQueryString();

        return Inertia::render('projects/performance', [
            'filters' => ['kind' => $kind, 'import' => $selected?->id, 'q' => $filters['q'] ?? null, 'sort' => $sort],
            'imports' => fn (): array => array_values(SearchConsoleImport::query()->where('project_id', $project->id)
                ->orderByDesc('period_end')->orderByDesc('id')->limit(50)->get()
                ->map(fn (SearchConsoleImport $import): array => $this->importRow($import))->all()),
            'summary' => fn (): array => $this->summary($project, $insights),
            'opportunities' => fn (): array => $insights->opportunities($project),
            'rows' => $rows === null ? null : $rows->through(fn ($row): array => [
                'key' => (string) $row->key_text,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'ctr' => (int) $row->impressions === 0 ? 0.0 : round((int) $row->clicks / (int) $row->impressions, 4),
                'position' => (float) $row->position,
            ]),
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function store(Request $request, Project $project, ImportSearchConsoleCsv $import): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:today'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $file = $data['file'];
        abort_unless($file instanceof UploadedFile, 422);
        $created = $import->handle(
            $project, $actor, (string) $file->getRealPath(), $file->getClientOriginalName(),
            CarbonImmutable::createFromFormat('Y-m-d', $data['period_start'])->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $data['period_end'])->startOfDay(),
        );

        return to_route('projects.performance.index', ['project' => $project, 'kind' => $created->kind]);
    }

    public function destroy(Request $request, Project $project, SearchConsoleImport $searchConsoleImport, ImportSearchConsoleCsv $import): RedirectResponse
    {
        Gate::authorize('update', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $import->delete($project, $searchConsoleImport, $actor);

        return to_route('projects.performance.index', ['project' => $project, 'kind' => $searchConsoleImport->kind]);
    }

    /** @return array<string, array{import: array<string, mixed>, totals: array<string, mixed>, previous: array<string, mixed>|null}|null> */
    private function summary(Project $project, SearchConsoleInsights $insights): array
    {
        $summary = [];
        foreach (SearchConsoleCsv::KINDS as $kind) {
            $latest = $insights->latest($project, $kind);
            $previous = $latest === null ? null : $insights->latest($project, $kind, 1);
            $summary[$kind] = $latest === null ? null : [
                'import' => $this->importRow($latest),
                'totals' => $insights->totals($latest) + ['days' => $latest->days()],
                'previous' => $previous === null ? null : $insights->totals($previous) + ['days' => $previous->days()],
            ];
        }

        return $summary;
    }

    /** @return array{id: int, kind: string, period_start: string, period_end: string, filename: string, rows: int, skipped: int, imported_at: string} */
    private function importRow(SearchConsoleImport $import): array
    {
        return [
            'id' => $import->id,
            'kind' => $import->kind,
            'period_start' => $import->period_start->toDateString(),
            'period_end' => $import->period_end->toDateString(),
            'filename' => $import->original_filename,
            'rows' => $import->row_count,
            'skipped' => $import->skipped_count,
            'imported_at' => $import->created_at->toIso8601String(),
        ];
    }
}
