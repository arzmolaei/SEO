<?php

namespace App\Services\Keywords;

use App\Enums\KeywordStatus;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UndoKeywordChangeSet
{
    public function __construct(private ProjectPolicy $projectPolicy) {}

    /** @throws AuthorizationException */
    public function handle(User $actor, Project $project, ChangeSet $changeSet): ChangeSet
    {
        if (! $this->projectPolicy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        if ((int) $changeSet->project_id !== (int) $project->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $changeSet): ChangeSet {
            $lockedChangeSet = ChangeSet::query()
                ->whereKey($changeSet->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedChangeSet->undone_at !== null) {
                throw new ConflictHttpException('This change set has already been undone.');
            }

            if ($lockedChangeSet->action !== 'keywords.bulk_status') {
                throw new ConflictHttpException('This change set cannot be undone safely.');
            }

            $changes = $lockedChangeSet->changes;

            if (! is_array($changes) || $changes === []) {
                throw new ConflictHttpException('This change set has no reversible changes.');
            }

            $keywordIds = [];

            foreach ($changes as $change) {
                if (! is_array($change) || ! isset($change['keyword_id'], $change['before'], $change['after'])) {
                    throw new ConflictHttpException('This change set contains invalid change data.');
                }

                $keywordIds[] = (int) $change['keyword_id'];
            }

            $keywords = Keyword::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $keywordIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($keywords->count() !== count(array_unique($keywordIds))) {
                throw new ConflictHttpException('One or more changed keywords no longer exist in this project.');
            }

            $reversions = [];

            foreach ($changes as $change) {
                $keywordId = (int) $change['keyword_id'];
                $before = is_string($change['before']) ? KeywordStatus::tryFrom($change['before']) : null;
                $after = is_string($change['after']) ? KeywordStatus::tryFrom($change['after']) : null;
                $keyword = $keywords->get($keywordId);

                if (! $keyword instanceof Keyword || $before === null || $after === null) {
                    throw new ConflictHttpException('This change set cannot be reversed safely.');
                }

                if ($keyword->status !== $after) {
                    throw new ConflictHttpException('A keyword has changed since this bulk action was applied.');
                }

                $reversions[] = [$keyword, $before];
            }

            foreach ($reversions as [$keyword, $before]) {
                $keyword->update(['status' => $before]);
            }

            $lockedChangeSet->update([
                'undone_at' => now(),
                'undone_by_id' => $actor->id,
            ]);

            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'change_set_id' => $lockedChangeSet->id,
                'event' => 'keywords.bulk_status.undone',
                'metadata' => [
                    'changed_count' => count($reversions),
                ],
            ]);

            return $lockedChangeSet->refresh();
        });
    }
}
