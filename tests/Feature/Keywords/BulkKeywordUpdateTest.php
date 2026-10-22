<?php

namespace Tests\Feature\Keywords;

use App\Enums\KeywordStatus;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkKeywordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_bulk_update_keyword_status_with_attributed_audit_records(): void
    {
        [$owner, $project] = $this->project();
        $first = $this->keyword($project, 'اول', KeywordStatus::Review);
        $second = $this->keyword($project, 'دوم', KeywordStatus::Ready);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$first->id, $second->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertDatabaseHas('keywords', [
            'id' => $first->id,
            'status' => KeywordStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('keywords', [
            'id' => $second->id,
            'status' => KeywordStatus::Ready->value,
        ]);
        $this->assertDatabaseCount('change_sets', 1);
        $this->assertDatabaseCount('activity_events', 1);

        $changeSet = DB::table('change_sets')->first();
        $activity = DB::table('activity_events')->first();

        $this->assertNotNull($changeSet);
        $this->assertNotNull($activity);
        $this->assertSame($project->workspace_id, $changeSet->workspace_id);
        $this->assertSame($project->id, $changeSet->project_id);
        $this->assertSame($owner->id, $changeSet->actor_id);
        $this->assertSame('keywords.bulk_status', $changeSet->action);
        $this->assertNull($changeSet->undone_at);
        $this->assertNull($changeSet->undone_by_id);

        $changes = json_decode((string) $changeSet->changes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            [
                'keyword_id' => $first->id,
                'before' => KeywordStatus::Review->value,
                'after' => KeywordStatus::Ready->value,
            ],
        ], $changes);

        $this->assertSame($project->workspace_id, $activity->workspace_id);
        $this->assertSame($project->id, $activity->project_id);
        $this->assertSame($owner->id, $activity->actor_id);
        $this->assertSame($changeSet->id, $activity->change_set_id);
        $this->assertSame('keywords.bulk_status.updated', $activity->event);
    }

    public function test_bulk_update_rejects_cross_project_keyword_ids_without_partial_changes_or_audit(): void
    {
        [$owner, $project] = $this->project();
        $local = $this->keyword($project, 'محلی', KeywordStatus::Review);
        [, $otherProject] = $this->project('Other Studio', 'Other.example');
        $foreign = $this->keyword($otherProject, 'خارجی', KeywordStatus::Review);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$local->id, $foreign->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('keywords', [
            'id' => $local->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertDatabaseHas('keywords', [
            'id' => $foreign->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertDatabaseCount('change_sets', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_non_manager_project_member_cannot_bulk_update_keywords(): void
    {
        [, $project] = $this->project();
        $keyword = $this->keyword($project, 'محدود', KeywordStatus::Review);
        $member = $this->addMember($project, WorkspaceRole::SeoSpecialist);

        $this->actingAs($member)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$keyword->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('keywords', [
            'id' => $keyword->id,
            'status' => KeywordStatus::Review->value,
        ]);
    }

    public function test_bulk_update_rejects_invalid_status_without_writes(): void
    {
        [$owner, $project] = $this->project();
        $keyword = $this->keyword($project, 'نامعتبر', KeywordStatus::Review);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$keyword->id],
                'status' => 'published',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('keywords', [
            'id' => $keyword->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertDatabaseCount('change_sets', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_manager_can_safely_undo_a_bulk_status_change_and_the_undo_is_audited(): void
    {
        [$owner, $project] = $this->project();
        $first = $this->keyword($project, 'بازگشت اول', KeywordStatus::Review);
        $second = $this->keyword($project, 'بازگشت دوم', KeywordStatus::Review);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$first->id, $second->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertRedirect();

        $changeSetId = (int) DB::table('change_sets')->value('id');

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-change-sets/{$changeSetId}/undo")
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertDatabaseHas('keywords', [
            'id' => $first->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertDatabaseHas('keywords', [
            'id' => $second->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertDatabaseHas('change_sets', [
            'id' => $changeSetId,
            'undone_by_id' => $owner->id,
        ]);
        $this->assertNotNull(DB::table('change_sets')->where('id', $changeSetId)->value('undone_at'));
        $this->assertDatabaseHas('activity_events', [
            'change_set_id' => $changeSetId,
            'actor_id' => $owner->id,
            'event' => 'keywords.bulk_status.undone',
        ]);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_undo_refuses_to_overwrite_a_keyword_changed_after_the_bulk_action(): void
    {
        [$owner, $project] = $this->project();
        $keyword = $this->keyword($project, 'بعداً تغییر کرده', KeywordStatus::Review);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$keyword->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertRedirect();

        $changeSetId = (int) DB::table('change_sets')->value('id');
        $keyword->refresh();
        $this->assertSame(KeywordStatus::Ready, $keyword->status);
        $keyword->update(['status' => KeywordStatus::Review]);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-change-sets/{$changeSetId}/undo")
            ->assertConflict();

        $this->assertDatabaseHas('keywords', [
            'id' => $keyword->id,
            'status' => KeywordStatus::Review->value,
        ]);
        $this->assertNull(DB::table('change_sets')->where('id', $changeSetId)->value('undone_at'));
        $this->assertDatabaseCount('activity_events', 1);
    }

    /** @return array{User, Project} */
    private function project(string $workspaceName = 'Studio', string $projectName = 'Example.com'): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, $workspaceName);
        $project = app(CreateProject::class)->handle($owner, $workspace, $projectName);

        return [$owner, $project];
    }

    private function keyword(Project $project, string $text, KeywordStatus $status): Keyword
    {
        return Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => $text,
            'normalized_text' => $text,
            'status' => $status,
        ]);
    }

    private function addMember(Project $project, WorkspaceRole $role): User
    {
        $member = User::factory()->create();

        DB::table('memberships')->insert([
            'workspace_id' => $project->workspace_id,
            'user_id' => $member->id,
            'role' => $role->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $member;
    }
}
