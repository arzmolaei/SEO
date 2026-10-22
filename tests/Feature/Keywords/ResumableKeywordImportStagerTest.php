<?php

namespace Tests\Feature\Keywords;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Keywords\ResumableKeywordImportStager;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ResumableKeywordImportStagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_interrupted_staging_persists_checkpoint_and_retry_resumes_without_duplicate_rows(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'source' => 'generic_csv',
            'original_filename' => 'large.csv',
            'mapping' => [
                'keyword' => 'Keyword',
                'volume' => 'Search Volume',
            ],
            'status' => 'pending',
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'warnings' => [],
            'errors' => [],
        ]);

        $stager = app(ResumableKeywordImportStager::class);

        try {
            $stager->handle($batch, $this->interruptedRows(), checkpointSize: 2);
            $this->fail('Expected the simulated import interruption to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated interruption', $exception->getMessage());
        }

        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame('simulated interruption', $batch->failure_message);
        $this->assertSame(2, $batch->rows()->count());
        $this->assertSame([1, 2], $batch->rows()->orderBy('row_number')->pluck('row_number')->all());

        $stager->handle($batch, $this->completeRows(), checkpointSize: 2);
        $batch->refresh();

        $this->assertSame('staged', $batch->status);
        $this->assertSame(4, $batch->processed_rows);
        $this->assertSame(4, $batch->total_rows);
        $this->assertNull($batch->failure_message);
        $this->assertSame(4, $batch->valid_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame(4, $batch->rows()->count());
        $this->assertSame(
            [1, 2, 3, 4],
            $batch->rows()->orderBy('row_number')->pluck('row_number')->all(),
        );
    }

    /** @return Generator<int, array<string, string>> */
    private function interruptedRows(): Generator
    {
        yield ['Keyword' => 'keyword-1', 'Search Volume' => '100'];
        yield ['Keyword' => 'keyword-2', 'Search Volume' => '200'];

        throw new RuntimeException('simulated interruption');
    }

    /** @return Generator<int, array<string, string>> */
    private function completeRows(): Generator
    {
        yield ['Keyword' => 'keyword-1', 'Search Volume' => '100'];
        yield ['Keyword' => 'keyword-2', 'Search Volume' => '200'];
        yield ['Keyword' => 'keyword-3', 'Search Volume' => '300'];
        yield ['Keyword' => 'keyword-4', 'Search Volume' => '400'];
    }
}
