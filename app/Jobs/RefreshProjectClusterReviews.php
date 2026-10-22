<?php

namespace App\Jobs;

use App\Domain\Keywords\KeywordIntent;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\ApplyKeywordClusterCandidates;
use App\Services\Keywords\ClassifyKeywordIntent;
use App\Services\Keywords\GenerateKeywordClusterCandidates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshProjectClusterReviews implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $actorId, public int $projectId) {}

    public function handle(
        GenerateKeywordClusterCandidates $generator,
        ApplyKeywordClusterCandidates $apply,
        ClassifyKeywordIntent $classifier,
        ProjectPolicy $policy,
    ): void {
        $actor = User::query()->findOrFail($this->actorId);
        $project = Project::query()->findOrFail($this->projectId);
        if (! $policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        $rows = (function () use ($project, $classifier): iterable {
            $cursor = DB::table('keywords as keywords')
                ->leftJoin('keyword_intent_assignments as intents', function (JoinClause $join): void {
                    $join->on('intents.keyword_id', '=', 'keywords.id')
                        ->where('intents.is_current', '=', true);
                })
                ->where('keywords.project_id', $project->id)
                ->whereNotExists(function (Builder $query): void {
                    $query->selectRaw('1')
                        ->from('keyword_cluster_members as memberships')
                        ->whereColumn('memberships.keyword_id', 'keywords.id');
                })
                ->orderBy('keywords.id')
                ->select(['keywords.id', 'keywords.project_id', 'keywords.display_text', 'intents.intent'])
                ->cursor();

            foreach ($cursor as $row) {
                $data = (array) $row;
                $text = (string) $data['display_text'];
                $intentValue = $data['intent'] ?? null;
                $intent = is_string($intentValue) ? KeywordIntent::tryFrom($intentValue) : null;

                yield [
                    'id' => (int) $data['id'],
                    'project_id' => (int) $data['project_id'],
                    'text' => $text,
                    'intent' => $intent ?? $classifier->classify($text)->intent,
                ];
            }
        })();

        $candidates = $generator->generate($rows);
        foreach (array_chunk($candidates, 50) as $batch) {
            $apply->handle($actor, $project, $batch);
        }
    }
}
