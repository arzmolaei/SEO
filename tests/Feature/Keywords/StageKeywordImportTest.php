<?php

namespace Tests\Feature\Keywords;

use App\Models\ImportBatch;
use App\Models\StagedKeywordRow;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageKeywordImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_stage_a_mapped_keyword_import_without_canonical_writes(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'keywords.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Search Volume',
                    'difficulty' => 'KD',
                ],
                'rows' => [
                    ['Keyword' => 'سئو سایت', 'Search Volume' => '1200', 'KD' => '31'],
                    ['Keyword' => 'خدمات سئو', 'Search Volume' => '800', 'KD' => '27'],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.source', 'generic_csv')
            ->assertJsonPath('data.original_filename', 'keywords.csv')
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.error_count', 0);

        $batchId = $response->json('data.id');

        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'project_id' => $project->id,
            'source' => 'generic_csv',
            'original_filename' => 'keywords.csv',
            'status' => 'staged',
            'total_rows' => 2,
            'valid_rows' => 2,
            'invalid_rows' => 0,
            'error_count' => 0,
        ]);

        $this->assertDatabaseCount('staged_keyword_rows', 2);
        $this->assertDatabaseHas('staged_keyword_rows', [
            'import_batch_id' => $batchId,
            'row_number' => 1,
            'keyword_original' => 'سئو سایت',
        ]);
        $this->assertDatabaseHas('staged_keyword_rows', [
            'import_batch_id' => $batchId,
            'row_number' => 2,
            'keyword_original' => 'خدمات سئو',
        ]);
    }

    public function test_missing_keyword_mapping_remains_staged_with_row_level_diagnostics(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'missing-keyword.csv',
                'mapping' => [
                    'volume' => 'Search Volume',
                ],
                'rows' => [
                    ['Search Volume' => '1200'],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.error_count', 2);

        $batch = ImportBatch::query()->findOrFail($response->json('data.id'));
        $row = StagedKeywordRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('row_number', 1)
            ->firstOrFail();

        $this->assertSame(['mapping.keyword_required'], $batch->errors);
        $this->assertFalse($row->is_valid);
        $this->assertSame(['keyword_mapping_missing'], $row->errors);
        $this->assertSame(['Search Volume' => '1200'], $row->raw_payload);
    }

    public function test_invalid_numeric_field_remains_staged_with_row_level_diagnostic(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'invalid-volume.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Search Volume',
                ],
                'rows' => [
                    ['Keyword' => 'سئو سایت', 'Search Volume' => 'many'],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.error_count', 1);

        $row = StagedKeywordRow::query()
            ->where('import_batch_id', $response->json('data.id'))
            ->where('row_number', 1)
            ->firstOrFail();

        $this->assertFalse($row->is_valid);
        $this->assertSame(['volume_not_numeric'], $row->errors);
        $this->assertSame('many', $row->mapped_payload['volume']);
        $this->assertSame(
            ['Keyword' => 'سئو سایت', 'Search Volume' => 'many'],
            $row->raw_payload,
        );
    }

    public function test_blank_and_missing_keyword_values_remain_staged_as_invalid_rows(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'empty-keywords.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                ],
                'rows' => [
                    ['Keyword' => '   '],
                    [],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 2)
            ->assertJsonPath('data.error_count', 2);

        $rows = StagedKeywordRow::query()
            ->where('import_batch_id', $response->json('data.id'))
            ->orderBy('row_number')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['keyword_required'], $rows[0]->errors);
        $this->assertSame(['Keyword' => '   '], $rows[0]->raw_payload);
        $this->assertSame(['keyword_required'], $rows[1]->errors);
        $this->assertSame([], $rows[1]->raw_payload);
    }

    public function test_conflicting_column_mapping_remains_staged_with_mapping_and_row_diagnostics(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'conflicting-mapping.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Keyword',
                ],
                'rows' => [
                    ['Keyword' => 'سئو سایت'],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.error_count', 2);

        $batch = ImportBatch::query()->findOrFail($response->json('data.id'));
        $row = StagedKeywordRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('row_number', 1)
            ->firstOrFail();

        $this->assertSame(['mapping.source_column_conflict'], $batch->errors);
        $this->assertFalse($row->is_valid);
        $this->assertSame(['mapping_conflict'], $row->errors);
        $this->assertSame(['Keyword' => 'سئو سایت'], $row->raw_payload);
        $this->assertSame(
            ['keyword' => 'سئو سایت', 'volume' => 'سئو سایت'],
            $row->mapped_payload,
        );
    }

    public function test_duplicate_source_rows_remain_staged_and_emit_a_row_warning(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $duplicate = ['Keyword' => 'سئو سایت', 'Search Volume' => '1200'];

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'duplicates.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Search Volume',
                ],
                'rows' => [$duplicate, $duplicate],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.warning_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $rows = StagedKeywordRow::query()
            ->where('import_batch_id', $response->json('data.id'))
            ->orderBy('row_number')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([], $rows[0]->warnings);
        $this->assertSame(['duplicate_row'], $rows[1]->warnings);
        $this->assertSame($duplicate, $rows[0]->raw_payload);
        $this->assertSame($duplicate, $rows[1]->raw_payload);
    }
}
