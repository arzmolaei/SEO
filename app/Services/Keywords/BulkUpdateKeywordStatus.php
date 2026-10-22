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
use InvalidArgumentException;

class BulkUpdateKeywordStatus
{
    public function __construct(private ProjectPolicy $projectPolicy) {}

    /**
     * @param  list<int>  $keywordIds
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Project $project, array $keywordIds, KeywordStatus $status): ?ChangeSet
    {
        if (! $this->projectPolicy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        $keywordIds = array_values(array_unique(array_map('intval', $keywordIds)));

        if ($keywordIds === []) {
            throw new InvalidArgumentException('At least one keyword is required.');
        }

        return DB::transaction(function () use ($actor, $project, $keywordIds, $status): ?ChangeSet {
            $keywords = Keyword::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $keywordIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($keywords->count() !== count($keywordIds)) {
                throw new InvalidArgumentException('Every keyword must belong to the selected project.');
            }

            $changes = [];

            foreach ($keywordIds as $keywordId) {
                $keyword = $keywords->get($keywordId);

                if (! $keyword instanceof Keyword) {
                    throw new InvalidArgumentException('Every keyword must belong to the selected project.');
                }

                $before = $keyword->status;

                if ($before === $status) {
                    continue;
                }

                $changes[] = [
                    'keyword_id' => $keyword->id,
                    'before' => $before->value,
                    'after' => $status->value,
                ];
            }

            if ($changes === []) {
                return null;
            }

            foreach ($changes as $change) {
                $keywords->get($change['keyword_id'])?->update([
                    'status' => $status,
                ]);
            }

            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'action' => 'keywords.bulk_status',
                'changes' => $changes,
            ]);

            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'change_set_id' => $changeSet->id,
                'event' => 'keywords.bulk_status.updated',
                'metadata' => [
                    'changed_count' => count($changes),
                    'requested_count' => count($keywordIds),
                ],
            ]);

            return $changeSet;
        });
    }
}
