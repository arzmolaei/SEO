<?php

namespace App\Http\Controllers;

use App\Models\InternalLinkDecision;
use App\Models\Project;
use App\Models\User;
use App\Services\Links\InternalLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InternalLinkController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, Project $project, InternalLinks $links): Response
    {
        Gate::authorize('view', $project);
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['open', 'done', 'dismissed'])],
            'page_id' => ['nullable', 'integer'],
        ]);
        $view = $filters['view'] ?? 'open';
        $pageId = isset($filters['page_id']) ? (int) $filters['page_id'] : null;
        $touches = fn (int $source, int $target): bool => $pageId === null || $source === $pageId || $target === $pageId;

        $decisions = InternalLinkDecision::query()->where('project_id', $project->id)
            ->get(['source_page_id', 'target_page_id', 'status', 'anchor'])
            ->keyBy(fn (InternalLinkDecision $decision): string => $decision->source_page_id.':'.$decision->target_page_id);
        $suggestions = $links->suggestions($project);
        $open = array_values(array_filter($suggestions, fn (array $item): bool => ! $decisions->has($item['source_page_id'].':'.$item['target_page_id'])
            && $touches($item['source_page_id'], $item['target_page_id'])));
        $suggested = collect($suggestions)->keyBy(fn (array $item): string => $item['source_page_id'].':'.$item['target_page_id']);

        if ($view === 'open') {
            $items = $open;
        } else {
            $items = $decisions->filter(fn (InternalLinkDecision $decision): bool => $decision->status === $view
                && $touches($decision->source_page_id, $decision->target_page_id))
                ->map(fn (InternalLinkDecision $decision, string $key): array => [
                    'source_page_id' => $decision->source_page_id,
                    'target_page_id' => $decision->target_page_id,
                    'anchor' => $decision->anchor ?? ($suggested->get($key)['anchor'] ?? ''),
                    'alternatives' => $suggested->get($key)['alternatives'] ?? [],
                    'shared' => $suggested->get($key)['shared'] ?? [],
                    'score' => $suggested->get($key)['score'] ?? null,
                ])->values()->all();
        }
        $current = max(1, (int) $request->integer('page', 1));
        $slice = array_slice($items, ($current - 1) * self::PER_PAGE, self::PER_PAGE);
        $pageIds = array_values(array_unique([...array_column($slice, 'source_page_id'), ...array_column($slice, 'target_page_id'), ...($pageId === null ? [] : [$pageId])]));
        $pages = DB::table('pages')->where('project_id', $project->id)->whereIn('id', $pageIds)->get(['id', 'title', 'url'])->keyBy('id');
        $label = fn (int $id): array => [
            'id' => $id,
            'title' => (string) ($pages->get($id)->title ?? $pages->get($id)->url ?? ''),
            'url' => $pages->get($id)->url ?? null,
        ];
        $paginator = new LengthAwarePaginator(
            array_map(fn (array $item): array => [
                'source' => $label($item['source_page_id']),
                'target' => $label($item['target_page_id']),
                'anchor' => $item['anchor'],
                'alternatives' => $item['alternatives'],
                'shared' => $item['shared'],
                'score' => $item['score'],
            ], $slice),
            count($items), self::PER_PAGE, $current,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('projects/internal-links', [
            'links' => $paginator,
            'filters' => ['view' => $view, 'page_id' => $pageId],
            'counts' => [
                'open' => count($open),
                'done' => $decisions->filter(fn (InternalLinkDecision $d): bool => $d->status === 'done' && $touches($d->source_page_id, $d->target_page_id))->count(),
                'dismissed' => $decisions->filter(fn (InternalLinkDecision $d): bool => $d->status === 'dismissed' && $touches($d->source_page_id, $d->target_page_id))->count(),
            ],
            'focus' => $pageId === null || ! $pages->has($pageId) ? null : $label($pageId),
            'mapped' => fn (): int => DB::table('cluster_targets')->where('project_id', $project->id)->whereNotNull('page_id')->count(),
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function decide(Request $request, Project $project, InternalLinks $links): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'source_page_id' => ['required', 'integer'],
            'target_page_id' => ['required', 'integer'],
            'status' => ['nullable', Rule::in(InternalLinkDecision::STATUSES)],
            'anchor' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $links->decide($project, $actor, (int) $data['source_page_id'], (int) $data['target_page_id'], $data['status'] ?? null, $data['anchor'] ?? null);

        return back();
    }
}
