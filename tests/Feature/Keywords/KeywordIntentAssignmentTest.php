<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Models\ActivityEvent;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use App\Services\Keywords\AssignKeywordIntent;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordIntentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_assign_intent_and_identical_reassignment_is_idempotent(): void
    {
        [$owner, $project, $keyword] = $this->keyword('خرید لپ تاپ');
        $service = app(AssignKeywordIntent::class);

        $first = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Transactional,
            IntentConfidence::High,
            'user',
            ['reason' => 'manual review'],
        );
        $second = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Transactional,
            IntentConfidence::High,
            'user',
            ['reason' => 'manual review'],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('keyword_intent_assignments', 1);
        $this->assertTrue($first->is_current);
        $this->assertSame($owner->id, $first->actor_id);
        $this->assertSame('user', $first->source);
        $this->assertSame(KeywordIntent::Transactional, $first->intent);
        $this->assertSame(IntentConfidence::High, $first->confidence);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_assignment_rejects_keyword_from_another_project(): void
    {
        [$owner, $project] = $this->project('Studio A', 'a.example');
        [, $otherProject, $foreignKeyword] = $this->keyword('کلمه خارجی', 'Studio B', 'b.example');

        $this->expectException(AuthorizationException::class);

        app(AssignKeywordIntent::class)->handle(
            $owner,
            $project,
            $foreignKeyword,
            KeywordIntent::Informational,
            IntentConfidence::Medium,
            'user',
        );

        $this->assertDatabaseCount('keyword_intent_assignments', 0);
    }

    public function test_manual_override_creates_immutable_history_and_is_attributed(): void
    {
        [$owner, $project, $keyword] = $this->keyword('بهترین لپ تاپ');
        $service = app(AssignKeywordIntent::class);

        $automatic = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Commercial,
            IntentConfidence::Medium,
            'rule',
            ['rule_ids' => ['commercial.best']],
        );
        $manual = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Transactional,
            IntentConfidence::High,
            'user',
            ['reason' => 'reviewed by SEO manager'],
        );

        $this->assertNotSame($automatic->id, $manual->id);
        $this->assertFalse($automatic->fresh()->is_current);
        $this->assertTrue($manual->fresh()->is_current);
        $this->assertSame($owner->id, $manual->actor_id);
        $this->assertDatabaseCount('keyword_intent_assignments', 2);
        $this->assertDatabaseHas('activity_events', [
            'actor_id' => $owner->id,
            'project_id' => $project->id,
            'event' => 'keywords.intent.assigned',
        ]);
    }

    public function test_manual_override_wins_over_later_automatic_suggestion(): void
    {
        [$owner, $project, $keyword] = $this->keyword('خرید خدمات سئو');
        $service = app(AssignKeywordIntent::class);

        $manual = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Transactional,
            IntentConfidence::High,
            'user',
        );
        $laterRule = $service->handle(
            $owner,
            $project,
            $keyword,
            KeywordIntent::Commercial,
            IntentConfidence::High,
            'rule',
            ['rule_ids' => ['commercial.service']],
        );

        $this->assertSame($manual->id, $laterRule->id);
        $this->assertDatabaseCount('keyword_intent_assignments', 1);
        $this->assertSame(KeywordIntent::Transactional, $manual->fresh()->intent);
    }

    /** @return array{User, Project} */
    private function project(string $workspaceName = 'Studio', string $projectName = 'example.com'): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, $workspaceName);
        $project = app(CreateProject::class)->handle($owner, $workspace, $projectName);

        return [$owner, $project];
    }

    /** @return array{User, Project, Keyword} */
    private function keyword(
        string $text,
        string $workspaceName = 'Studio',
        string $projectName = 'example.com',
    ): array {
        [$owner, $project] = $this->project($workspaceName, $projectName);
        $keyword = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => $text,
            'normalized_text' => $text,
            'status' => 'review',
        ]);

        return [$owner, $project, $keyword];
    }
}
