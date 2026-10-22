<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\SavedKeywordViewState;
use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class UpdateSavedKeywordView
{
    /** @throws AuthorizationException */
    public function handle(
        User $actor,
        Project $project,
        SavedKeywordView $view,
        mixed $name,
        mixed $state,
    ): SavedKeywordView {
        $this->assertOwnedBy($actor, $project, $view);

        if (! is_string($name)) {
            throw new InvalidArgumentException('Saved keyword view name must be a string.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Saved keyword view name must be between 1 and 80 characters.');
        }

        $validatedState = SavedKeywordViewState::fromMixed($state);

        if (SavedKeywordView::query()
            ->where('user_id', $actor->id)
            ->where('project_id', $project->id)
            ->where('name', $name)
            ->whereKeyNot($view->id)
            ->exists()) {
            throw new InvalidArgumentException('A saved keyword view with this name already exists.');
        }

        $view->update([
            'name' => $name,
            'schema_version' => SavedKeywordViewState::SCHEMA_VERSION,
            'state' => $validatedState->toArray(),
        ]);

        return $view->refresh();
    }

    /** @throws AuthorizationException */
    private function assertOwnedBy(User $actor, Project $project, SavedKeywordView $view): void
    {
        if (
            $view->user_id !== $actor->id
            || $view->project_id !== $project->id
            || $view->workspace_id !== $project->workspace_id
        ) {
            throw new AuthorizationException;
        }
    }
}
