<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\KeywordGridQuery;
use App\Domain\Keywords\SavedKeywordViewState;
use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class ResolveKeywordGridView
{
    /**
     * @param  array<string, mixed>  $requestQuery
     * @return array{filters: KeywordGridQuery, activeView: SavedKeywordView|null}
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Project $project, array $requestQuery): array
    {
        $activeView = null;
        $explicitViewId = $this->viewId($requestQuery['view'] ?? null);
        $hasExplicitGridInput = $this->hasExplicitGridInput($requestQuery);

        if ($explicitViewId !== null) {
            $activeView = SavedKeywordView::query()->findOrFail($explicitViewId);
            $this->assertOwnedBy($actor, $project, $activeView);
        } elseif (! $hasExplicitGridInput) {
            $activeView = SavedKeywordView::query()
                ->where('user_id', $actor->id)
                ->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)
                ->where('is_default', true)
                ->first();
        }

        $effectiveQuery = [];

        if ($activeView !== null) {
            if ($activeView->schema_version !== SavedKeywordViewState::SCHEMA_VERSION) {
                throw new InvalidArgumentException('Unsupported saved keyword view schema version.');
            }

            $effectiveQuery = SavedKeywordViewState::fromMixed($activeView->state)->query;
        }

        foreach (KeywordGridQuery::INPUT_FIELDS as $field) {
            if (array_key_exists($field, $requestQuery)) {
                $effectiveQuery[$field] = $requestQuery[$field];
            }
        }

        return [
            'filters' => KeywordGridQuery::fromArray($effectiveQuery),
            'activeView' => $activeView,
        ];
    }

    /** @param array<string, mixed> $requestQuery */
    private function hasExplicitGridInput(array $requestQuery): bool
    {
        foreach (KeywordGridQuery::INPUT_FIELDS as $field) {
            if (array_key_exists($field, $requestQuery)) {
                return true;
            }
        }

        return false;
    }

    private function viewId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
        } else {
            throw new InvalidArgumentException('Saved keyword view id must be a positive integer.');
        }

        if ($id < 1) {
            throw new InvalidArgumentException('Saved keyword view id must be a positive integer.');
        }

        return $id;
    }

    /** @throws AuthorizationException */
    private function assertOwnedBy(User $actor, Project $project, SavedKeywordView $view): void
    {
        if (
            $view->user_id !== $actor->id
            || $view->workspace_id !== $project->workspace_id
            || $view->project_id !== $project->id
        ) {
            throw new AuthorizationException;
        }
    }
}
