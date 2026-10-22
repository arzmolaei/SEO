<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateKeywordCluster
{
    public function __construct(private ProjectPolicy $policy) {}

    /** @param list<mixed> $memberIds */
    public function handle(
        User $actor,
        Project $project,
        string $name,
        Keyword $primary,
        array $memberIds,
        KeywordIntent $intent = KeywordIntent::Unknown,
        IntentConfidence $confidence = IntentConfidence::Low,
        bool $locked = false,
        string $origin = 'user',
        ?string $automationKey = null,
    ): KeywordCluster {
        if (! $this->policy->manageKeywords($actor, $project) || $primary->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if (! in_array($origin, ['user', 'lexical'], true)) {
            throw new InvalidArgumentException('Unsupported cluster origin.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Cluster name must contain 1 to 255 characters.');
        }
        $validatedIds = [];
        foreach ($memberIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Member IDs must be positive integers.');
            }
            $validatedIds[] = $id;
        }
        $memberIds = array_values(array_unique($validatedIds));
        sort($memberIds, SORT_NUMERIC);
        if (! in_array($primary->id, $memberIds, true)) {
            throw new InvalidArgumentException('The primary keyword must be an explicit member.');
        }

        return DB::transaction(function () use ($actor, $project, $name, $primary, $memberIds, $intent, $confidence, $locked, $origin, $automationKey): KeywordCluster {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $keywords = Keyword::query()->where('project_id', $project->id)->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
            if ($keywords->count() !== count($memberIds)) {
                throw new InvalidArgumentException('Every member must exist in the selected project.');
            }
            if (KeywordClusterMember::query()->whereIn('keyword_id', $memberIds)->exists()) {
                throw new InvalidArgumentException('A keyword already belongs to a cluster. Move it explicitly.');
            }
            $cluster = KeywordCluster::query()->create([
                'project_id' => $project->id, 'primary_keyword_id' => $primary->id,
                'created_by_id' => $actor->id, 'name' => $name, 'intent' => $intent,
                'origin' => $origin, 'automation_key' => $automationKey,
                'confidence' => $confidence, 'review_state' => ClusterReviewState::Pending, 'is_locked' => $locked,
            ]);
            $changes = [];
            $validatedIds = [];
            foreach ($memberIds as $id) {
                $cluster->members()->create(['project_id' => $project->id, 'keyword_id' => $id]);
                $changes[] = ['keyword_id' => $id, 'before' => 'unclustered', 'after' => (string) $cluster->id];
            }
            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'actor_id' => $actor->id, 'action' => 'keywords.cluster.create', 'changes' => $changes,
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'actor_id' => $actor->id, 'change_set_id' => $changeSet->id, 'event' => 'keywords.cluster.created',
                'metadata' => ['cluster_id' => $cluster->id, 'name' => $name, 'primary_keyword_id' => $primary->id,
                    'source' => $origin, 'member_count' => count($memberIds), 'intent' => $intent->value, 'confidence' => $confidence->value, 'is_locked' => $locked],
            ]);

            return $cluster;
        });
    }
}
