<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\SavedKeywordViewState;
use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use InvalidArgumentException;

class CreateSavedKeywordView
{
    public function handle(
        User $actor,
        Project $project,
        mixed $name,
        mixed $state,
    ): SavedKeywordView {
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
            ->exists()) {
            throw new InvalidArgumentException('A saved keyword view with this name already exists.');
        }

        return SavedKeywordView::query()->create([
            'user_id' => $actor->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'name' => $name,
            'schema_version' => SavedKeywordViewState::SCHEMA_VERSION,
            'state' => $validatedState->toArray(),
            'is_default' => false,
        ]);
    }
}
