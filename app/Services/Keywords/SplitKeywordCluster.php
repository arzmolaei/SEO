<?php

namespace App\Services\Keywords;

use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SplitKeywordCluster
{
    public function __construct(private ClusterMutation $mutation, private CreateKeywordCluster $create) {}

    /** @param list<int> $ids */
    public function handle(User $actor, Project $project, KeywordCluster $source, array $ids, string $name, Keyword $primary, ?Keyword $replacement = null): ?ChangeSet
    {
        if ($ids === [] || ! in_array($primary->id, $ids, true) || count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Distinct split members including primary required.');
        }

        return $this->mutation->handle($actor, $project, [$source->id], 'keywords.clusters.split', function ($clusters) use ($actor, $project, $source, $ids, $name, $primary, $replacement): array {
            $current = $clusters->get($source->id);
            if (! $current instanceof KeywordCluster) {
                throw new InvalidArgumentException('Missing cluster.');
            }
            $this->mutation->editable($current);
            $members = $current->members()->whereIn('keyword_id', $ids)->orderBy('id')->get();
            if ($members->count() !== count($ids) || $current->members()->count() <= count($ids)) {
                throw new ConflictHttpException('Split must leave members in the source.');
            }
            if (in_array($current->primary_keyword_id, $ids, true)) {
                if ($replacement === null || in_array($replacement->id, $ids, true) || ! $current->members()->where('keyword_id', $replacement->id)->exists()) {
                    throw new ConflictHttpException('Select a remaining member as replacement primary.');
                }
                $current->update(['primary_keyword_id' => $replacement->id]);
            }
            $current->update(['archived_at' => now()]);
            foreach ($members as $member) {
                $member->delete();
            }
            $child = $this->create->handle($actor, $project, $name, $primary, $ids, $current->intent, $current->confidence);
            $current->update([...$this->mutation->manual(), 'archived_at' => null]);

            return [$child->id];
        });
    }
}
