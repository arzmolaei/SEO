<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignKeywordIntent
{
    /** @var list<string> */
    private const SOURCES = ['rule', 'user', 'future_serp', 'future_ai'];

    public function __construct(private ProjectPolicy $projectPolicy) {}

    /**
     * @param  array<string, mixed>  $evidence
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(
        User $actor,
        Project $project,
        Keyword $keyword,
        KeywordIntent $intent,
        IntentConfidence $confidence,
        string $source,
        array $evidence = [],
    ): KeywordIntentAssignment {
        if (! $this->projectPolicy->manageKeywords($actor, $project) || $keyword->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported intent assignment source.');
        }

        return DB::transaction(function () use ($actor, $project, $keyword, $intent, $confidence, $source, $evidence): KeywordIntentAssignment {
            $current = KeywordIntentAssignment::query()
                ->where('project_id', $project->id)
                ->where('keyword_id', $keyword->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if ($current instanceof KeywordIntentAssignment) {
                if ($current->source === 'user' && $source !== 'user') {
                    return $current;
                }

                if (
                    $current->intent === $intent
                    && $current->confidence === $confidence
                    && $current->source === $source
                    && $current->evidence === $evidence
                ) {
                    return $current;
                }

                $current->update(['is_current' => false]);
            }

            $assignment = KeywordIntentAssignment::query()->create([
                'keyword_id' => $keyword->id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'intent' => $intent,
                'confidence' => $confidence,
                'source' => $source,
                'evidence' => $evidence,
                'is_current' => true,
            ]);

            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'action' => 'keywords.intent.assign',
                'changes' => [[
                    'keyword_id' => $keyword->id,
                    'before' => $current?->intent->value ?? KeywordIntent::Unknown->value,
                    'after' => $intent->value,
                ]],
            ]);

            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'change_set_id' => $changeSet->id,
                'event' => 'keywords.intent.assigned',
                'metadata' => [
                    'keyword_id' => $keyword->id,
                    'assignment_id' => $assignment->id,
                    'previous_assignment_id' => $current?->id,
                    'intent' => $intent->value,
                    'confidence' => $confidence->value,
                    'source' => $source,
                ],
            ]);

            return $assignment;
        });
    }
}
