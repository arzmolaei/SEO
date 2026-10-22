<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\KeywordRowReader;
use App\Domain\Keywords\StagedKeywordRowValidator;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Keywords\ProcessKeywordCsvImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessKeywordCsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_interrupted_streaming_import_resumes_from_checkpoint_without_duplicate_rows(): void
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
            'status' => 'queued',
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'warnings' => [],
            'errors' => [],
        ]);

        $reader = new class implements KeywordRowReader
        {
            public int $attempt = 0;

            /** @return Generator<int, array<string, string>> */
            public function rows(string $path): Generator
            {
                $this->attempt++;

                for ($row = 1; $row <= 5; $row++) {
                    if ($this->attempt === 1 && $row === 3) {
                        throw new RuntimeException('Simulated stream interruption.');
                    }

                    yield [
                        'Keyword' => "keyword-{$row}",
                        'Search Volume' => (string) ($row * 100),
                    ];
                }
            }
        };

        $processor = new ProcessKeywordCsvImport(
            reader: $reader,
            rowValidator: app(StagedKeywordRowValidator::class),
        );

        try {
            $processor->handle($batch, 'unused.csv', chunkSize: 2);
            $this->fail('The first import attempt should be interrupted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated stream interruption.', $exception->getMessage());
        }

        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(2, $batch->checkpoint_row);
        $this->assertSame(2, $batch->rows()->count());
        $this->assertNotNull($batch->failed_at);
        $this->assertSame('Simulated stream interruption.', $batch->failure_message);

        $processor->handle($batch, 'unused.csv', chunkSize: 2);
        $batch->refresh();

        $this->assertSame('staged', $batch->status);
        $this->assertSame(5, $batch->total_rows);
        $this->assertSame(5, $batch->processed_rows);
        $this->assertSame(5, $batch->checkpoint_row);
        $this->assertSame(5, $batch->valid_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame(5, $batch->rows()->count());
        $this->assertNull($batch->failed_at);
        $this->assertNull($batch->failure_message);
        $this->assertNotNull($batch->completed_at);
    }
}
