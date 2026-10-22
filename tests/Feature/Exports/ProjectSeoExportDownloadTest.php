<?php

namespace Tests\Feature\Exports;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\Project;
use App\Models\User;
use App\Services\Exports\ProjectSeoExportSchema;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ProjectSeoExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_managers_can_download_the_csv_and_response_metadata_matches_exact_body(): void
    {
        [$owner, $project] = $this->project();
        Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'سئو\u{200C}سایت',
            'normalized_text' => 'سئو سایت',
        ]);

        foreach ([WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::SeoManager] as $role) {
            $actor = $role === WorkspaceRole::Owner ? $owner : $this->member($project, $role);
            $response = $this->actingAs($actor)->get("/projects/{$project->id}/exports/seo-plan.csv");

            $response->assertOk();
            $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
            $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $response->assertHeader('cache-control', 'private, no-store');
            $response->assertHeader('pragma', 'no-cache');
            $response->assertHeader('x-content-type-options', 'nosniff');
            $response->assertHeader('x-seo-export-schema', ProjectSeoExportSchema::ID);
            $response->assertHeader('x-seo-export-row-count', '1');
            $this->assertMatchesRegularExpression(
                '/attachment; filename=seo-plan-v1-[^;]+\.csv/',
                (string) $response->headers->get('content-disposition'),
            );

            $body = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
            $this->assertSame(hash('sha256', $body), $response->headers->get('x-seo-export-sha256'));
            $this->assertNotNull($response->headers->get('x-seo-export-generated-at'));
        }
    }

    public function test_non_manager_roles_and_outsider_cannot_export_bulk_project_data(): void
    {
        [, $project] = $this->project();

        foreach ([WorkspaceRole::SeoSpecialist, WorkspaceRole::ContentWriter, WorkspaceRole::Viewer] as $role) {
            $actor = $this->member($project, $role);

            $this->actingAs($actor)
                ->get("/projects/{$project->id}/exports/seo-plan.csv")
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->create())
            ->get("/projects/{$project->id}/exports/seo-plan.csv")
            ->assertForbidden();
    }

    public function test_known_integrity_failure_redirects_with_stable_persian_message_and_never_returns_partial_csv(): void
    {
        [$owner, $project] = $this->project();
        $keyword = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'تعارض',
            'normalized_text' => 'تعارض',
        ]);
        foreach ([KeywordIntent::Informational, KeywordIntent::Commercial] as $intent) {
            KeywordIntentAssignment::query()->create([
                'project_id' => $project->id,
                'keyword_id' => $keyword->id,
                'actor_id' => $owner->id,
                'intent' => $intent,
                'confidence' => IntentConfidence::Medium,
                'source' => 'test',
                'evidence' => [],
                'is_current' => true,
            ]);
        }

        $this->actingAs($owner)
            ->from("/projects/{$project->id}/keywords")
            ->get("/projects/{$project->id}/exports/seo-plan.csv")
            ->assertRedirect("/projects/{$project->id}/keywords")
            ->assertSessionHas('export_error', 'خروجی به‌دلیل ناسازگاری داده‌های نیت قابل تولید نیست.');
    }

    /** @return array{User,Project} */
    private function project(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Export workspace');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        return [$owner, $project];
    }

    private function member(Project $project, WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        $project->workspace->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}
