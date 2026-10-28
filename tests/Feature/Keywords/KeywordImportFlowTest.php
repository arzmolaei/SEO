<?php

namespace Tests\Feature\Keywords;

use App\Enums\WorkspaceRole;
use App\Jobs\CommitKeywordImport;
use App\Jobs\ProcessKeywordImportFile;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeywordImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = storage_path('framework/testing/keyword-imports-'.bin2hex(random_bytes(6)));
        config(['keywords.import.storage_root' => $this->uploads]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploads);
        parent::tearDown();
    }

    public function test_owner_uploads_maps_processes_and_commits_a_persian_csv(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        $csv = "\xEF\xBB\xBFKeyword,Search Volume,Note\r\nسئو سایت,1200,a\r\nسئو سايت,900,b\r\nخدمات سئو,800,c\r\n,50,empty\r\n";

        $response = $this->actingAs($owner)->post("/projects/{$project->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('کلمات.csv', $csv),
        ]);
        $batch = ImportBatch::query()->where('project_id', $project->id)->sole();
        $response->assertRedirect(route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]));
        $this->assertSame('awaiting_mapping', $batch->status);
        $this->assertSame('کلمات.csv', $batch->original_filename);
        // The original file is preserved byte-for-byte inside the private durable root.
        $this->assertSame($csv, File::get($this->uploads.'/'.$batch->source_path));

        $this->actingAs($owner)->get(route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/imports')
                ->where('activeBatch.status', 'awaiting_mapping')
                ->where('activeBatch.headers', ['Keyword', 'Search Volume', 'Note'])
                ->has('activeBatch.preview', 4)
                ->where('activeBatch.preview.0.Keyword', 'سئو سایت')
                ->missing('activeBatch.source_path'));

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Keyword', 'volume' => 'Search Volume'])
            ->assertRedirect();
        $batch->refresh();
        $this->assertSame('staged', $batch->status, 'sync queue processes immediately in tests');
        $this->assertSame(['keyword' => 'Keyword', 'volume' => 'Search Volume'], $batch->mapping);
        $this->assertSame(1, $batch->invalid_rows);

        $this->actingAs($owner)->get(route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeBatch.status', 'staged')
                ->where('activeBatch.valid_rows', 3)
                ->has('activeBatch.invalidRows.data', 1));

        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/commit")->assertRedirect();
        $this->assertSame('committed', $batch->refresh()->status);
        $this->assertSame(2, Keyword::query()->where('project_id', $project->id)->count(), 'Yeh variants normalize to one keyword');
        // Original source text is preserved separately from the normalized keyword.
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('keyword_sources')->where('original_text', 'سئو سايت')->exists());
    }

    public function test_pasted_keywords_become_a_single_column_original_file(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::SeoManager);

        $this->actingAs($owner)->post("/projects/{$project->id}/imports", [
            'text' => "خرید بک لینک\r\n\r\nآموزش سئو\n",
        ])->assertRedirect();

        $batch = ImportBatch::query()->sole();
        $this->assertSame('paste', $batch->source);
        $this->actingAs($owner)->get(route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeBatch.headers', ['Keyword'])
                ->has('activeBatch.preview', 2));
    }

    public function test_processing_and_commit_run_on_the_queue_and_report_server_truth(): void
    {
        Queue::fake();
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        $this->actingAs($owner)->post("/projects/{$project->id}/imports", ['text' => "یک\nدو\n"]);
        $batch = ImportBatch::query()->sole();

        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Keyword']);
        Queue::assertPushed(ProcessKeywordImportFile::class, fn (ProcessKeywordImportFile $job): bool => $job->batchId === $batch->id);
        $this->assertSame('queued', $batch->refresh()->status);
        $this->actingAs($owner)->get(route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]))
            ->assertInertia(fn (Assert $page) => $page->where('activeBatch.status', 'queued')->where('activeBatch.processed_rows', 0));

        (new ProcessKeywordImportFile($batch->id))->handle(app(\App\Services\Keywords\ProcessKeywordCsvImport::class));
        $this->assertSame('staged', $batch->refresh()->status);

        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/commit");
        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/commit");
        Queue::assertPushed(CommitKeywordImport::class, 1);
        $this->assertNotNull($batch->refresh()->commit_requested_at);
    }

    public function test_discard_removes_staged_rows_and_the_original_file(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        $this->actingAs($owner)->post("/projects/{$project->id}/imports", ['text' => "یک\nدو\n"]);
        $batch = ImportBatch::query()->sole();
        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Keyword']);
        $path = $this->uploads.'/'.$batch->refresh()->source_path;
        $this->assertFileExists($path);

        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/discard")->assertRedirect();

        $batch->refresh();
        $this->assertSame('discarded', $batch->status);
        $this->assertSame($owner->id, $batch->discarded_by);
        $this->assertSame(0, $batch->rows()->count());
        $this->assertFileDoesNotExist($path);
        $this->actingAs($owner)->post("/projects/{$project->id}/imports/{$batch->id}/commit")->assertStatus(409);
    }

    public function test_mapping_must_name_existing_distinct_columns(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        $this->actingAs($owner)->post("/projects/{$project->id}/imports", ['text' => "یک\n"]);
        $batch = ImportBatch::query()->sole();

        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Missing'])
            ->assertSessionHasErrors('keyword');
        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Keyword', 'volume' => 'Keyword'])
            ->assertSessionHasErrors('volume');
        $this->assertSame('awaiting_mapping', $batch->refresh()->status);
    }

    public function test_uploads_are_bounded_and_must_contain_data(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        config(['keywords.import.max_bytes' => 64]);

        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('big.csv', "Keyword\n".str_repeat("کلمه\n", 40)),
        ])->assertSessionHasErrors('file');
        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', "Keyword\n"),
        ])->assertSessionHasErrors('file');
        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('dup.csv', "Keyword,Keyword\nیک,دو\n"),
        ])->assertSessionHasErrors('file');
        $this->actingAs($owner)->from('/x')->post("/projects/{$project->id}/imports", [])->assertSessionHasErrors('file');
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_project_boundaries_and_roles_are_enforced(): void
    {
        [$owner, $project] = $this->projectMember(WorkspaceRole::Owner);
        [$stranger, $otherProject] = $this->projectMember(WorkspaceRole::Owner);
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        Membership::query()->create(['workspace_id' => $project->workspace_id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $this->actingAs($owner)->post("/projects/{$project->id}/imports", ['text' => "یک\n"]);
        $batch = ImportBatch::query()->sole();

        $this->actingAs($stranger)->get("/projects/{$project->id}/imports")->assertForbidden();
        $this->actingAs($stranger)->post("/projects/{$project->id}/imports", ['text' => "x\n"])->assertForbidden();
        // A batch is only reachable through its own project.
        $this->actingAs($stranger)->post("/projects/{$otherProject->id}/imports/{$batch->id}/discard")->assertNotFound();
        $this->actingAs($viewer)->get("/projects/{$project->id}/imports")->assertOk();
        $this->actingAs($viewer)->post("/projects/{$project->id}/imports/{$batch->id}/mapping", ['keyword' => 'Keyword'])->assertForbidden();
        $this->assertSame('awaiting_mapping', $batch->refresh()->status);
    }

    /** @return array{User, Project} */
    private function projectMember(WorkspaceRole $role): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $workspace = Workspace::query()->create(['name' => 'فضای '.$user->id]);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
        $project = Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'پروژه '.$user->id]);

        return [$user, $project];
    }
}
