<?php

namespace Tests\Feature\Exports;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\Project;
use App\Models\User;
use App\Services\Exports\ExportProjectSeoPlan;
use App\Services\Exports\ProjectSeoExportException;
use App\Services\Exports\ProjectSeoExportSchema;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExportProjectSeoPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_project_returns_bom_header_metadata_and_closed_transaction(): void
    {
        $project = $this->project('پروژه خالی');
        $generatedAt = CarbonImmutable::parse('2026-10-23T00:00:00Z');
        $initialTransactionLevel = DB::connection()->transactionLevel();

        $result = app(ExportProjectSeoPlan::class)->handle($project, $generatedAt);

        $this->assertSame($initialTransactionLevel, DB::connection()->transactionLevel());
        $this->assertSame(ProjectSeoExportSchema::ID, $result->schemaVersion());
        $this->assertSame(0, $result->rowCount());
        $this->assertSame($generatedAt->toIso8601String(), $result->generatedAt()->toIso8601String());
        $this->assertSame('text/csv; charset=UTF-8', $result->mimeType());
        $this->assertMatchesRegularExpression('/^seo-plan-v1-[a-z0-9-]+-20261023T000000Z\.csv$/', $result->filename());

        $bytes = $this->readResult($result);
        $this->assertSame("\xEF\xBB\xBF".implode(',', ProjectSeoExportSchema::HEADERS)."\r\n", $bytes);
        $this->assertSame(hash('sha256', $bytes), $result->sha256());
        $result->close();
        $result->close();
    }

    public function test_export_crosses_multiple_250_row_batches_in_ascending_keyword_order(): void
    {
        $project = $this->project('batch-project');
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 501; $index++) {
            $rows[] = [
                'project_id' => $project->id,
                'display_text' => 'کلمه '.$index,
                'normalized_text' => 'کلمه '.$index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Keyword::query()->insert($rows);
        $initialTransactionLevel = DB::connection()->transactionLevel();

        $result = app(ExportProjectSeoPlan::class)->handle(
            $project,
            CarbonImmutable::parse('2026-10-23T01:02:03Z'),
        );

        $this->assertSame(501, $result->rowCount());
        $this->assertSame($initialTransactionLevel, DB::connection()->transactionLevel());
        $bytes = $this->readResult($result);
        $this->assertSame(hash('sha256', $bytes), $result->sha256());
        $lines = preg_split('/\r\n/', rtrim(substr($bytes, 3), "\r\n"));
        $this->assertIsArray($lines);
        $this->assertCount(502, $lines);
        $first = str_getcsv($lines[1], ',', '"', '');
        $last = str_getcsv($lines[501], ',', '"', '');
        $this->assertSame(ProjectSeoExportSchema::ID, $first[0]);
        $this->assertSame((string) Keyword::query()->where('project_id', $project->id)->min('id'), $first[4]);
        $this->assertSame((string) Keyword::query()->where('project_id', $project->id)->max('id'), $last[4]);
        $result->close();
    }

    public function test_filename_never_uses_project_path_syntax_and_persian_only_name_falls_back_to_id(): void
    {
        $unsafe = $this->project('../../My Site / evil\\name');
        $persian = $this->project('فقط فارسی');
        $at = CarbonImmutable::parse('2026-10-23T04:05:06Z');

        $unsafeResult = app(ExportProjectSeoPlan::class)->handle($unsafe, $at);
        $persianResult = app(ExportProjectSeoPlan::class)->handle($persian, $at);

        $this->assertSame('seo-plan-v1-my-site-evil-name-20261023T040506Z.csv', $unsafeResult->filename());
        $this->assertSame('seo-plan-v1-'.$persian->id.'-20261023T040506Z.csv', $persianResult->filename());
        $this->assertStringNotContainsString('..', $unsafeResult->filename());
        $this->assertStringNotContainsString('/', $unsafeResult->filename());
        $this->assertStringNotContainsString('\\', $unsafeResult->filename());
        $unsafeResult->close();
        $persianResult->close();
    }

    public function test_formula_text_is_neutralized_while_exact_text_json_stays_original(): void
    {
        $project = $this->project('=SUM(1,1)');
        Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => '+keyword',
            'normalized_text' => '+keyword',
        ]);

        $result = app(ExportProjectSeoPlan::class)->handle($project);
        $bytes = $this->readResult($result);
        $line = preg_split('/\r\n/', rtrim(substr($bytes, 3), "\r\n"))[1] ?? '';
        $parsed = str_getcsv($line, ',', '"', '');

        $this->assertSame("'=SUM(1,1)", $parsed[3]);
        $this->assertSame("'+keyword", $parsed[5]);
        $exact = json_decode($parsed[73], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('=SUM(1,1)', $exact['project_name']);
        $this->assertSame('+keyword', $exact['keyword_display_text']);
        $result->close();
    }

    public function test_generation_failure_rolls_back_and_returns_no_result(): void
    {
        $project = $this->project('خراب');
        $keyword = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'تعارض نیت',
            'normalized_text' => 'تعارض نیت',
        ]);
        $owner = $project->workspace->memberships()->firstOrFail()->user;
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
        $initialTransactionLevel = DB::connection()->transactionLevel();

        try {
            app(ExportProjectSeoPlan::class)->handle($project);
            $this->fail('Expected export failure.');
        } catch (ProjectSeoExportException $exception) {
            $this->assertSame('export_integrity_current_intent', $exception->codeName());
        }

        $this->assertSame($initialTransactionLevel, DB::connection()->transactionLevel());
    }

    private function project(string $name): Project
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, $name.' workspace');
        $project = app(CreateProject::class)->handle($owner, $workspace, $name);
        $project->setRelation('workspace', $workspace->load('memberships.user'));

        return $project;
    }

    private function readResult(mixed $result): string
    {
        $stream = $result->stream();
        $this->assertIsResource($stream);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        $this->assertIsString($bytes);

        return $bytes;
    }
}
