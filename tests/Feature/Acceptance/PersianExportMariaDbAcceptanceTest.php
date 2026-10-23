<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Project;
use App\Models\User;
use App\Services\Exports\ProjectSeoExportSchema;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersianExportMariaDbAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PERSIAN_EXPORT_CONNECTED') !== '1') {
            $this->markTestSkipped('Connected Persian export acceptance runs only in the MariaDB CI gate.');
        }
    }

    public function test_real_mariadb_http_export_preserves_persian_domain_truth_and_access_isolation(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(0, DB::connection()->transactionLevel());

        $owner = User::factory()->create(['email_verified_at' => now()]);
        $workspace = app(CreateWorkspace::class)->handle($owner, 'فضای صادرات');
        $project = app(CreateProject::class)->handle($owner, $workspace, '=پروژه فارسی');

        [$primary, $secondary] = $this->importKeywords($owner, $project);
        $this->addDifficultyEvidence($primary, '18.5000');
        $this->addDifficultyEvidence($secondary, '45.0000');

        KeywordIntentAssignment::query()->create([
            'project_id' => $project->id,
            'keyword_id' => $primary->id,
            'actor_id' => $owner->id,
            'intent' => KeywordIntent::Commercial,
            'confidence' => IntentConfidence::High,
            'source' => 'manual-connected',
            'evidence' => ['acceptance' => true],
            'is_current' => true,
        ]);

        $cluster = app(CreateKeywordCluster::class)->handle(
            $owner,
            $project,
            '=خوشه سئو',
            $primary,
            [$primary->id, $secondary->id],
            KeywordIntent::Commercial,
            IntentConfidence::High,
            false,
            'user',
            null,
            ClusterReviewState::Reviewed,
        );
        $cluster->update(['business_relevance' => 5]);
        $page = app(CreatePlannedPage::class)->handle($owner, $project, '@صفحه خدمات سئو');
        $page->update(['url' => '=https://example.test/seo']);
        app(MapClusterToTarget::class)->handle(
            $owner,
            $project,
            $cluster,
            ClusterTargetType::PlannedPage,
            $page,
        );

        $foreignProject = app(CreateProject::class)->handle($owner, $workspace, 'foreign.example');
        $this->keyword($foreignProject, 'نباید در خروجی باشد', 'نباید در خروجی باشد');

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $workspace->memberships()->create([
            'user_id' => $viewer->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        $response = $this->actingAs($owner)
            ->get("/projects/{$project->id}/exports/seo-plan.csv")
            ->assertOk()
            ->assertHeader('x-seo-export-schema', ProjectSeoExportSchema::ID)
            ->assertHeader('x-seo-export-row-count', '2');

        $body = $response->streamedContent();
        $this->assertSame(hash('sha256', $body), $response->headers->get('x-seo-export-sha256'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringNotContainsString('نباید در خروجی باشد', $body);
        $this->assertStringNotContainsString('secret_should_not_export', $body);
        $this->assertSame(0, DB::connection()->transactionLevel());

        $rows = $this->csvRows($body);
        $this->assertCount(2, $rows);
        $byKeywordId = collect($rows)->keyBy(fn (array $row): int => (int) $row['keyword_id']);
        $primaryRow = $byKeywordId->get($primary->id);
        $secondaryRow = $byKeywordId->get($secondary->id);
        $this->assertIsArray($primaryRow);
        $this->assertIsArray($secondaryRow);

        $this->assertSame("'=پروژه فارسی", $primaryRow['project_name']);
        $this->assertSame("'+سئو\u{200C}سایت", $primaryRow['keyword_display_text']);
        $this->assertSame('2500.0000', $primaryRow['volume_value']);
        $this->assertSame('commercial', $primaryRow['intent']);
        $this->assertSame($cluster->id, (int) $secondaryRow['cluster_id']);
        $this->assertSame($primary->id, (int) $secondaryRow['priority_basis_keyword_id']);
        $this->assertSame('2500.0000', $secondaryRow['priority_volume_value']);
        $this->assertNotSame('', $secondaryRow['priority_score']);
        $this->assertSame($page->id, (int) $secondaryRow['page_id']);
        $this->assertSame("'@صفحه خدمات سئو", $secondaryRow['page_title']);
        $this->assertSame("'=https://example.test/seo", $secondaryRow['page_url']);

        $exact = json_decode($primaryRow['exact_text_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('=پروژه فارسی', $exact['project_name']);
        $this->assertSame("+سئو\u{200C}سایت", $exact['keyword_display_text']);
        $this->assertSame('=خوشه سئو', $exact['cluster_name']);

        $this->actingAs($viewer)
            ->get("/projects/{$project->id}/exports/seo-plan.csv")
            ->assertForbidden();
    }

    /** @return array{Keyword,Keyword} */
    private function importKeywords(User $owner, Project $project): array
    {
        $primaryText = "+سئو\u{200C}سایت";
        $imports = [
            [
                'source' => 'connected-import-old',
                'filename' => 'connected-old.csv',
                'primary_volume' => '1000',
                'secondary_volume' => '50',
            ],
            [
                'source' => 'connected-import-latest',
                'filename' => 'connected-latest.csv',
                'primary_volume' => '2500',
                'secondary_volume' => '75',
            ],
        ];

        foreach ($imports as $import) {
            $batch = app(StageKeywordImport::class)->handle(
                actor: $owner,
                project: $project,
                source: $import['source'],
                originalFilename: $import['filename'],
                mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
                rows: [
                    ['Keyword' => $primaryText, 'Volume' => $import['primary_volume']],
                    ['Keyword' => 'خدمات سئو', 'Volume' => $import['secondary_volume']],
                ],
            );
            app(CommitKeywordImportBatch::class)->handle($owner, $batch);
        }

        $primary = Keyword::query()
            ->where('project_id', $project->id)
            ->where('display_text', $primaryText)
            ->firstOrFail();
        $secondary = Keyword::query()
            ->where('project_id', $project->id)
            ->where('display_text', 'خدمات سئو')
            ->firstOrFail();

        return [$primary, $secondary];
    }

    private function keyword(Project $project, string $display, string $normalized): Keyword
    {
        return Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => $display,
            'normalized_text' => $normalized,
            'status' => 'review',
        ]);
    }

    private function addDifficultyEvidence(Keyword $keyword, string $difficulty): void
    {
        $source = KeywordSource::query()
            ->where('keyword_id', $keyword->id)
            ->orderByDesc('id')
            ->firstOrFail();
        $rawPayload = is_array($source->raw_payload) ? $source->raw_payload : [];
        $source->update(['raw_payload' => [
            ...$rawPayload,
            'secret_should_not_export' => 'hidden',
        ]]);

        KeywordMetric::query()->create([
            'keyword_id' => $keyword->id,
            'keyword_source_id' => $source->id,
            'metric' => 'difficulty',
            'value' => $difficulty,
            'observed_at' => now()->addMinute(),
        ]);
    }

    /** @return list<array<string,string>> */
    private function csvRows(string $body): array
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $this->assertSame(ProjectSeoExportSchema::HEADERS, $headers);
        $rows = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $row = array_combine($headers, $values);
            $this->assertIsArray($row);
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
