<?php

namespace Tests\Feature\Exports;

use App\Models\Keyword;
use App\Models\User;
use App\Services\Exports\ProjectSeoExportSnapshot;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeoExportSnapshotCursorTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_cursor_continues_after_last_emitted_keyword_without_duplicates(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Cursor workspace');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Cursor project');
        $first = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'کلمه اول',
            'normalized_text' => 'کلمه اول',
        ]);
        $second = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'کلمه دوم',
            'normalized_text' => 'کلمه دوم',
        ]);

        $snapshot = app(ProjectSeoExportSnapshot::class);
        $firstBatch = $snapshot->loadBatch($project, null, 1);
        $secondBatch = $snapshot->loadBatch($project, $firstBatch['next_after_id'], 1);

        $this->assertSame([$first->id], array_column($firstBatch['rows'], 'keyword_id'));
        $this->assertSame($first->id, $firstBatch['next_after_id']);
        $this->assertSame([$second->id], array_column($secondBatch['rows'], 'keyword_id'));
        $this->assertNull($secondBatch['next_after_id']);
    }
}
