<?php

namespace Tests\Feature\Exports;

use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use App\Services\Exports\ExportProjectSeoPlan;
use App\Services\Exports\ProjectSeoExportSchema;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeoExportLargeDatasetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PERSIAN_EXPORT_LARGE') !== '1') {
            $this->markTestSkipped('Large Persian export acceptance runs only in its permanent CI gate.');
        }
    }

    public function test_ten_thousand_keywords_export_in_order_through_private_temp_stream(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Large Export Workspace');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'large-export.example');
        $now = now();

        for ($offset = 0; $offset < 10_000; $offset += 500) {
            $rows = [];
            for ($index = $offset + 1; $index <= $offset + 500; $index++) {
                $rows[] = [
                    'project_id' => $project->id,
                    'display_text' => 'کلمه آزمایشی '.$index,
                    'normalized_text' => 'کلمه آزمایشی '.$index,
                    'status' => 'review',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Keyword::query()->insert($rows);
        }

        $expectedFirstId = (int) Keyword::query()->where('project_id', $project->id)->min('id');
        $expectedLastId = (int) Keyword::query()->where('project_id', $project->id)->max('id');
        $result = app(ExportProjectSeoPlan::class)->handle($project);

        $this->assertSame(10_000, $result->rowCount());
        $stream = $result->stream();
        $this->assertIsResource($stream);
        $metadata = stream_get_meta_data($stream);
        $this->assertStringStartsWith('php://temp', (string) ($metadata['uri'] ?? ''));

        rewind($stream);
        $hash = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 65_536);
            $this->assertNotFalse($chunk);
            if ($chunk !== '') {
                hash_update($hash, $chunk);
            }
        }
        $this->assertSame($result->sha256(), hash_final($hash));

        rewind($stream);
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        $header = fgetcsv($stream, escape: '');
        $this->assertSame(ProjectSeoExportSchema::HEADERS, $header);

        $rowCount = 0;
        $firstKeywordId = null;
        $lastKeywordId = null;
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $rowCount++;
            $firstKeywordId ??= (int) $row[4];
            $lastKeywordId = (int) $row[4];
        }

        $this->assertSame(10_000, $rowCount);
        $this->assertSame($expectedFirstId, $firstKeywordId);
        $this->assertSame($expectedLastId, $lastKeywordId);
        $result->close();

        $this->assertFileDoesNotExist(public_path('seo-plan.csv'));
        $this->assertFileDoesNotExist(storage_path('app/public/seo-plan.csv'));
    }
}
