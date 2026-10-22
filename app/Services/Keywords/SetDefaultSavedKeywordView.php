<?php

namespace App\Services\Keywords;

use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SetDefaultSavedKeywordView
{
    /** @throws AuthorizationException */
    public function handle(User $actor, Project $project, SavedKeywordView $view): SavedKeywordView
    {
        return DB::transaction(function () use ($actor, $project, $view): SavedKeywordView {
            $views = SavedKeywordView::query()
                ->where('user_id', $actor->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->get();

            $target = $views->firstWhere('id', $view->id);

            if (
                ! $target instanceof SavedKeywordView
                || $target->workspace_id !== $project->workspace_id
            ) {
                throw new AuthorizationException;
            }

            SavedKeywordView::query()
                ->where('user_id', $actor->id)
                ->where('project_id', $project->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $target->update(['is_default' => true]);

            return $target->refresh();
        });
    }
}
