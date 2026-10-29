<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
use App\Enums\WorkspaceRole;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\ContentBrief;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ReviewItem;
use App\Models\SavedKeywordView;
use App\Models\SearchConsoleImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use App\Services\Backup\CreateSystemBackup;
use App\Services\Backup\EnvironmentSettingsUpdater;
use App\Services\Backup\RecoverInterruptedRestore;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\RestorePreview;
use App\Services\Backup\RestoreSystemBackup;
use App\Services\Backup\VerifyRestoreBundle;
use App\Services\Content\ContentBriefs;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\SearchConsole\ImportSearchConsoleCsv;
use App\Services\Tasks\ProjectTasks;
use App\Services\Workspaces\CreateWorkspace;
use App\Support\InstallationState;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Lifecycle\ProductVersion;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Throwable;

class BackupRestoreMariaDbAcceptanceTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private string $privateUploads;

    private string $publicUploads;

    private string $restoreRoot;

    private string $restoreUploads;

    private string $journalPath;

    private string $installationLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BACKUP_RESTORE_CONNECTED') !== '1') {
            $this->markTestSkipped('Connected backup/restore acceptance runs only in the MariaDB CI gate.');
        }

        $this->directory = storage_path('framework/testing/backup-restore-acceptance-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->restoreRoot = $this->directory.'/lifecycle/restore';
        $this->restoreUploads = $this->restoreRoot.'/uploads';
        $this->journalPath = $this->restoreRoot.'/state.json';
        $this->installationLockPath = $this->directory.'/.installed';

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
        File::ensureDirectoryExists($this->restoreUploads);

        $this->configureConnectedDatabases();
        $this->migrateFresh('backup_source');
        $this->migrateFresh('backup_destination');

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->installationLockPath,
            'installer.public_registration' => false,
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.restore.upload_path' => $this->restoreUploads,
            'backup.restore.journal_path' => $this->journalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
            'session.driver' => 'file',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);

        $this->app->instance(
            EnvironmentSettingsUpdater::class,
            new EnvironmentSettingsUpdater($this->environmentPath),
        );
    }

    protected function tearDown(): void
    {
        foreach (['backup_source', 'backup_destination'] as $connection) {
            try {
                $this->dropAllTables($connection);
            } catch (Throwable) {
                // Preserve the original acceptance failure if cleanup is unavailable.
            }
        }

        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_real_mariadb_round_trip_and_interrupted_restore_recovery_preserve_lifecycle_contract(): void
    {
        $sourceKey = 'base64:'.base64_encode(str_repeat('s', 32));
        $destinationKey = 'base64:'.base64_encode(str_repeat('d', 32));
        $sourceInstallationId = str_repeat('a', 32);
        $destinationInstallationId = str_repeat('b', 32);
        $sourceDatabaseFingerprint = str_repeat('1', 64);
        $destinationDatabaseFingerprint = str_repeat('2', 64);
        $productVersion = (new ProductVersion)->requireDistributable();
        $bundlePath = $this->directory.'/source.seobak';

        $this->useDatabase('backup_source');
        config([
            'app.key' => $sourceKey,
            'app.locale' => 'fa',
            'app.fallback_locale' => 'en',
            'installer.public_registration' => true,
        ]);

        $sourceOwner = User::factory()->create([
            'name' => 'مالک منبع',
            'email' => 'source-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'password' => 'SourcePass!123',
        ]);
        $sourcePasswordHash = (string) $sourceOwner->getRawOriginal('password');
        $sourceEncryptedSecret = $this->encrypter($sourceKey)->encryptString('source-two-factor-secret');
        DB::table('users')->where('id', $sourceOwner->id)->update([
            'two_factor_secret' => $sourceEncryptedSecret,
        ]);

        $sourceWorkspace = app(CreateWorkspace::class)->handle($sourceOwner, 'فضای کاری منبع');
        $sourceProject = app(CreateProject::class)->handle($sourceOwner, $sourceWorkspace, 'پروژه سئو فارسی');
        $isolatedProject = app(CreateProject::class)->handle($sourceOwner, $sourceWorkspace, 'پروژه جدا');
        $viewer = User::factory()->create([
            'name' => 'بازبین منبع',
            'email' => 'source-viewer@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'password' => 'ViewerPass!123',
        ]);
        Membership::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'user_id' => $viewer->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        DB::table('passkeys')->insert([
            'user_id' => $sourceOwner->id,
            'name' => 'کلید منبع',
            'credential_id' => 'source-passkey-credential',
            'credential' => json_encode(['id' => 'source-passkey'], JSON_THROW_ON_ERROR),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$primary, $secondary] = $this->importPersianKeywords($sourceOwner, $sourceProject);
        $latestSource = KeywordSource::query()->where('keyword_id', $primary->id)->latest('id')->firstOrFail();
        KeywordMetric::query()->create([
            'keyword_id' => $primary->id,
            'keyword_source_id' => $latestSource->id,
            'metric' => 'difficulty',
            'value' => '27.5000',
            'observed_at' => now()->addMinute(),
        ]);

        Keyword::query()->create([
            'project_id' => $isolatedProject->id,
            'display_text' => 'کلیدواژه پروژه جدا',
            'normalized_text' => 'کلیدواژه پروژه جدا',
            'status' => 'review',
        ]);

        SavedKeywordView::query()->create([
            'user_id' => $sourceOwner->id,
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $sourceProject->id,
            'name' => 'نمای ذخیره‌شده فارسی',
            'schema_version' => 1,
            'state' => [
                'query' => ['text' => 'سئو'],
                'columns' => ['keyword', 'volume'],
            ],
            'is_default' => true,
        ]);

        KeywordIntentAssignment::query()->create([
            'keyword_id' => $primary->id,
            'project_id' => $sourceProject->id,
            'actor_id' => $sourceOwner->id,
            'intent' => KeywordIntent::Commercial,
            'confidence' => IntentConfidence::High,
            'source' => 'acceptance-manual',
            'evidence' => ['reason' => 'پذیرش بازیابی'],
            'is_current' => true,
        ]);

        $cluster = app(CreateKeywordCluster::class)->handle(
            $sourceOwner,
            $sourceProject,
            'خوشه خدمات سئو',
            $primary,
            [$primary->id, $secondary->id],
            KeywordIntent::Commercial,
            IntentConfidence::High,
            false,
            'user',
            null,
            ClusterReviewState::Reviewed,
        );
        $page = app(CreatePlannedPage::class)->handle($sourceOwner, $sourceProject, 'صفحه خدمات سئو');
        app(MapClusterToTarget::class)->handle(
            $sourceOwner,
            $sourceProject,
            $cluster,
            ClusterTargetType::PlannedPage,
            $page,
        );

        ReviewItem::query()->create([
            'project_id' => $sourceProject->id,
            'keyword_id' => $secondary->id,
            'type' => ReviewItemType::Intent,
            'status' => 'pending',
            'fingerprint' => hash('sha256', 'backup-restore-acceptance-review'),
            'suggestion' => ['intent' => 'commercial', 'note' => 'بازبینی فارسی'],
        ]);

        $changeSet = ChangeSet::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $sourceProject->id,
            'actor_id' => $sourceOwner->id,
            'action' => 'acceptance.backup.restore',
            'changes' => [[
                'keyword_id' => $primary->id,
                'before' => 'قدیم',
                'after' => 'جدید',
            ]],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $sourceProject->id,
            'actor_id' => $sourceOwner->id,
            'change_set_id' => $changeSet->id,
            'event' => 'acceptance.backup.restore',
            'metadata' => ['persian' => 'داده پایدار'],
        ]);

        $crawl = CrawlRun::query()->create([
            'project_id' => $sourceProject->id,
            'created_by_id' => $sourceOwner->id,
            'mode' => 'site',
            'start_url' => 'https://example.test/',
            'normalized_start_url' => 'https://example.test/',
            'allowed_hosts' => ['example.test'],
            'settings' => ['include' => [], 'exclude' => [], 'max_depth' => 10, 'max_urls' => 1000, 'delay_ms' => 500, 'include_www' => false],
            'status' => 'completed',
            'discovered_count' => 1,
            'fetched_count' => 1,
            'finished_at' => now(),
        ]);
        $crawlUrl = CrawlUrl::query()->create([
            'crawl_run_id' => $crawl->id,
            'page_id' => $page->id,
            'url' => 'https://example.test/',
            'normalized_url' => 'https://example.test/',
            'url_hash' => hash('sha256', 'https://example.test/'),
            'depth' => 0,
            'source' => 'start',
            'state' => 'fetched',
            'status_code' => 200,
            'title' => 'عنوان خزش فارسی',
            'h1' => ['خدمات سئو'],
            'indexable' => true,
            'fetched_at' => now(),
        ]);
        $briefs = app(ContentBriefs::class);
        $brief = $briefs->generate($sourceProject, $sourceOwner, $cluster->id);
        $briefs->update($sourceProject, $brief, $sourceOwner, [
            'title' => 'بریف فارسی پایدار', 'headings' => ['سرفصل یک', 'پرسش‌های متداول'], 'notes' => "یادداشت\u{200C}دار",
            'status' => 'review', 'due_on' => '2026-12-01', 'published_on' => null,
        ]);
        $briefSnapshot = $brief->fresh()?->getRawOriginal();
        $task = app(ProjectTasks::class)->create($sourceProject, $sourceOwner, [
            'title' => 'کار فارسی پایدار', 'notes' => 'یادداشت کار', 'status' => 'done', 'due_on' => '2026-11-15',
            'page_id' => $page->id, 'keyword_cluster_id' => $cluster->id,
        ]);
        $taskSnapshot = $task->fresh()?->getRawOriginal();
        $searchCsv = $this->directory.'/Queries.csv';
        File::put($searchCsv, "\xEF\xBB\xBFپرسش‌های برتر,کلیک‌ها,نمایش‌ها,CTR,جایگاه\nخدمات سئو,۱۲,۴۰۰,3%,۶٫۴\n");
        $searchImport = app(ImportSearchConsoleCsv::class)->handle(
            $sourceProject, $sourceOwner, $searchCsv, 'Queries.csv',
            CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'),
        );
        $searchImportSnapshot = $searchImport->fresh()?->getRawOriginal();
        $searchRowSnapshot = (array) DB::table('search_console_rows')->where('search_console_import_id', $searchImport->id)->sole();
        $crawlSnapshot = $crawl->fresh()->getRawOriginal();
        $crawlUrlSnapshot = $crawlUrl->fresh()->getRawOriginal();

        File::put($this->privateUploads.'/private-source.txt', 'private-source-durable');
        File::put($this->publicUploads.'/public-source.txt', 'public-source-durable');
        $this->seedVolatileRows('source', $sourceOwner->id);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => $productVersion,
            'installation_id' => $sourceInstallationId,
            'database_fingerprint' => $sourceDatabaseFingerprint,
            'owner_user_id' => $sourceOwner->id,
        ]);

        $catalog = $this->app->make(ApplicationTableCatalog::class);
        $sourceCounts = [];
        foreach ($catalog->tableNames() as $table) {
            $sourceCounts[$table] = DB::table($table)->count();
            $this->assertGreaterThan(0, $sourceCounts[$table], "Source fixture must cover durable table {$table}.");
        }

        $backup = $this->app->make(CreateSystemBackup::class)->handle();
        try {
            $bytes = stream_get_contents($backup->stream());
            $this->assertIsString($bytes);
            $this->assertNotSame('', $bytes);
            File::put($bundlePath, $bytes);
        } finally {
            $backup->close();
        }
        $this->assertFileExists($bundlePath);

        $destination = $this->provisionDestination(
            $destinationKey,
            $destinationInstallationId,
            $destinationDatabaseFingerprint,
            'destination-owner@example.test',
        );
        $destinationEnvironmentBeforeRestore = (new InstallerEnvironment($this->environmentPath))->values();
        $destinationInstallationBeforeRestore = (new InstallationState)->details();
        $this->assertIsArray($destinationInstallationBeforeRestore);

        $preview = $this->verifyBundle($bundlePath);
        $this->app->make(RestoreSystemBackup::class)->handle($preview->digest());

        $this->assertSame('backup_destination', DB::getDefaultConnection());
        $this->assertSame($sourceKey, config('app.key'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertTrue((bool) config('installer.public_registration'));

        $environment = (new InstallerEnvironment($this->environmentPath))->values();
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'MAIL_PASSWORD', 'EXTERNAL_API_TOKEN'] as $key) {
            $this->assertSame($destinationEnvironmentBeforeRestore[$key], $environment[$key], "Destination environment key {$key} must be preserved.");
        }
        $this->assertSame($sourceKey, $environment['APP_KEY']);
        $this->assertSame('fa', $environment['APP_LOCALE']);
        $this->assertSame('true', $environment['SELF_HOST_REGISTRATION']);

        $installation = (new InstallationState)->details();
        $this->assertIsArray($installation);
        $this->assertSame($destinationInstallationId, $installation['installation_id']);
        $this->assertSame($destinationDatabaseFingerprint, $installation['database_fingerprint']);
        $this->assertSame($sourceOwner->id, $installation['owner_user_id']);
        $this->assertSame($sourceInstallationId, $installation['restored_from_installation_id']);
        $this->assertNotEmpty($installation['restored_at']);

        foreach ($sourceCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Restored durable table {$table} must match the source snapshot.");
        }
        $this->assertVolatileTablesEmpty();
        $this->assertDatabaseMissing('users', ['email' => $destination['owner_email']]);
        $this->assertDatabaseHas('users', ['email' => 'source-owner@example.test']);
        $this->assertDatabaseHas('memberships', [
            'user_id' => $viewer->id,
            'workspace_id' => $sourceWorkspace->id,
            'role' => WorkspaceRole::Viewer->value,
        ]);
        $this->assertDatabaseHas('saved_keyword_views', ['name' => 'نمای ذخیره‌شده فارسی']);
        $this->assertDatabaseHas('review_items', ['fingerprint' => hash('sha256', 'backup-restore-acceptance-review')]);
        $this->assertDatabaseHas('passkeys', ['credential_id' => 'source-passkey-credential']);
        $this->assertDatabaseMissing('passkeys', ['credential_id' => 'destination-passkey-credential']);
        $this->assertSame($crawlSnapshot, CrawlRun::query()->findOrFail($crawl->id)->getRawOriginal());
        $this->assertSame($crawlUrlSnapshot, CrawlUrl::query()->findOrFail($crawlUrl->id)->getRawOriginal());
        $this->assertSame($briefSnapshot, ContentBrief::query()->findOrFail($brief->id)->getRawOriginal());
        $this->assertSame($taskSnapshot, ProjectTask::query()->findOrFail($task->id)->getRawOriginal());
        $this->assertSame($searchImportSnapshot, SearchConsoleImport::query()->findOrFail($searchImport->id)->getRawOriginal());
        $this->assertSame($searchRowSnapshot, (array) DB::table('search_console_rows')->where('search_console_import_id', $searchImport->id)->sole());
        $this->assertSame(2, Keyword::query()->where('project_id', $sourceProject->id)->count());
        $this->assertSame(1, Keyword::query()->where('project_id', $isolatedProject->id)->count());
        $this->assertSame("+سئو\u{200C}سایت", Keyword::query()->findOrFail($primary->id)->display_text);
        $this->assertSame('private-source-durable', File::get($this->privateUploads.'/private-source.txt'));
        $this->assertSame('public-source-durable', File::get($this->publicUploads.'/public-source.txt'));

        $restoredOwner = User::query()->findOrFail($sourceOwner->id);
        $this->assertSame($sourcePasswordHash, $restoredOwner->getRawOriginal('password'));
        $this->assertTrue(Hash::check('SourcePass!123', $restoredOwner->password));
        $restoredEncryptedSecret = DB::table('users')->where('id', $sourceOwner->id)->value('two_factor_secret');
        $this->assertIsString($restoredEncryptedSecret);
        $this->assertSame(
            'source-two-factor-secret',
            $this->encrypter((string) config('app.key'))->decryptString($restoredEncryptedSecret),
        );

        $journal = new RestoreJournal($this->journalPath);
        $completedState = $journal->state();
        $this->assertIsArray($completedState);
        $this->assertSame('complete', $completedState['phase']);
        $this->assertSame([], File::files($this->restoreUploads));
        $this->assertFalse(File::exists($this->restoreRoot.'/safety'));
        $this->assertFalse(File::exists($this->restoreRoot.'/staging'));

        try {
            $this->app->make(RestoreSystemBackup::class)->handle($preview->digest());
            $this->fail('A completed installation unexpectedly accepted a second restore.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_digest_mismatch', $exception->codeName());
        }

        $this->migrateFresh('backup_destination');
        $recoveryDestination = $this->provisionDestination(
            $destinationKey,
            $destinationInstallationId,
            $destinationDatabaseFingerprint,
            'recovery-destination-owner@example.test',
        );
        $recoveryEnvironmentBeforeRestore = (new InstallerEnvironment($this->environmentPath))->values();
        $recoveryInstallationBeforeRestore = (new InstallationState)->details();
        $this->assertIsArray($recoveryInstallationBeforeRestore);

        $recoveryPreview = $this->verifyBundle($bundlePath);
        $blockedEnvironmentPath = $this->environmentPath.'.blocked';
        $this->assertTrue(File::move($this->environmentPath, $blockedEnvironmentPath));

        try {
            $this->app->make(RestoreSystemBackup::class)->handle($recoveryPreview->digest());
            $this->fail('Restore unexpectedly completed despite the injected post-database environment failure.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_environment_update_failed', $exception->codeName());
        }

        $failedState = (new RestoreJournal($this->journalPath))->state();
        $this->assertIsArray($failedState);
        $this->assertSame('recovery_required', $failedState['phase']);
        $this->assertDatabaseHas('users', ['email' => 'source-owner@example.test']);
        $this->assertDatabaseMissing('users', ['email' => $recoveryDestination['owner_email']]);
        $this->assertSame('private-source-durable', File::get($this->privateUploads.'/private-source.txt'));
        $this->assertTrue(File::move($blockedEnvironmentPath, $this->environmentPath));

        $this->app->make(RecoverInterruptedRestore::class)->handle();

        $this->assertDatabaseHas('users', ['email' => $recoveryDestination['owner_email']]);
        $this->assertDatabaseMissing('users', ['email' => 'source-owner@example.test']);
        $this->assertDatabaseHas('passkeys', ['credential_id' => 'destination-passkey-credential']);
        foreach ($catalog->tableNames() as $table) {
            if (in_array($table, ['users', 'passkeys', 'workspaces', 'memberships'], true)) {
                continue;
            }
            $this->assertSame(0, DB::table($table)->count(), "Recovered clean bootstrap table {$table} must be empty.");
        }
        $this->assertSame([], File::allFiles($this->privateUploads));
        $this->assertSame([], File::allFiles($this->publicUploads));
        $this->assertVolatileTablesEmpty();
        $this->assertSame($destinationKey, config('app.key'));
        $this->assertSame('de', config('app.locale'));
        $this->assertFalse((bool) config('installer.public_registration'));
        $this->assertSame($recoveryInstallationBeforeRestore, (new InstallationState)->details());

        $recoveredEnvironment = (new InstallerEnvironment($this->environmentPath))->values();
        foreach ($recoveryEnvironmentBeforeRestore as $key => $value) {
            $this->assertSame($value, $recoveredEnvironment[$key] ?? null, "Recovery must restore environment key {$key} exactly.");
        }
        $this->assertSame($recoveryDestination['password_hash'], User::query()->where('email', $recoveryDestination['owner_email'])->firstOrFail()->getRawOriginal('password'));
        $this->assertNull((new RestoreJournal($this->journalPath))->state());
        $this->assertSame([], File::files($this->restoreUploads));
        $this->assertFalse(File::exists($this->restoreRoot.'/safety'));
        $this->assertFalse(File::exists($this->restoreRoot.'/staging'));
    }

    /** @return array{Keyword, Keyword} */
    private function importPersianKeywords(User $owner, Project $project): array
    {
        $primaryText = "+سئو\u{200C}سایت";
        $batch = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'backup-restore-acceptance',
            originalFilename: 'persian-keywords.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: [
                ['Keyword' => $primaryText, 'Volume' => '2500'],
                ['Keyword' => 'خدمات سئو', 'Volume' => '900'],
            ],
        );
        app(CommitKeywordImportBatch::class)->handle($owner, $batch);

        return [
            Keyword::query()->where('project_id', $project->id)->where('display_text', $primaryText)->firstOrFail(),
            Keyword::query()->where('project_id', $project->id)->where('display_text', 'خدمات سئو')->firstOrFail(),
        ];
    }

    /** @return array{owner_email:string,password_hash:string} */
    private function provisionDestination(
        string $key,
        string $installationId,
        string $databaseFingerprint,
        string $ownerEmail,
    ): array {
        $this->useDatabase('backup_destination');
        $this->resetLifecycleFilesystem();
        $this->writeDestinationEnvironment($key);

        config([
            'app.key' => $key,
            'app.locale' => 'de',
            'app.fallback_locale' => 'en',
            'installer.public_registration' => false,
        ]);

        $owner = User::factory()->create([
            'name' => 'Destination bootstrap owner',
            'email' => $ownerEmail,
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'password' => 'DestinationPass!123',
        ]);
        $workspace = Workspace::query()->create(['name' => 'Destination bootstrap workspace']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        DB::table('passkeys')->insert([
            'user_id' => $owner->id,
            'name' => 'Destination passkey',
            'credential_id' => 'destination-passkey-credential',
            'credential' => json_encode(['id' => 'destination-passkey'], JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => $installationId,
            'database_fingerprint' => $databaseFingerprint,
            'owner_user_id' => $owner->id,
        ]);

        $this->seedVolatileRows('destination', $owner->id);
        $this->assertSame(
            $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection()),
            $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection('backup_source')),
        );

        return [
            'owner_email' => $ownerEmail,
            'password_hash' => (string) $owner->getRawOriginal('password'),
        ];
    }

    private function verifyBundle(string $bundlePath): RestorePreview
    {
        return $this->app->make(VerifyRestoreBundle::class)->handle(
            new UploadedFile($bundlePath, 'source.seobak', 'application/octet-stream', null, true),
        );
    }

    private function seedVolatileRows(string $prefix, int $userId): void
    {
        $now = time();
        DB::table('password_reset_tokens')->insert([
            'email' => "{$prefix}-reset@example.test",
            'token' => "{$prefix}-reset-token",
            'created_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => "{$prefix}-session",
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'backup-restore-acceptance',
            'payload' => "{$prefix}-session-payload",
            'last_activity' => $now,
        ]);
        DB::table('cache')->insert([
            'key' => "{$prefix}-cache",
            'value' => "{$prefix}-cache-value",
            'expiration' => $now + 3600,
        ]);
        DB::table('cache_locks')->insert([
            'key' => "{$prefix}-lock",
            'owner' => "{$prefix}-owner",
            'expiration' => $now + 3600,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'acceptance',
            'payload' => "{$prefix}-job-payload",
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
        DB::table('job_batches')->insert([
            'id' => "{$prefix}-batch",
            'name' => "{$prefix}-batch",
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'finished_at' => null,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => "{$prefix}-failed-job-uuid",
            'connection' => 'sync',
            'queue' => 'acceptance',
            'payload' => "{$prefix}-failed-payload",
            'exception' => "{$prefix}-failed-exception",
        ]);
    }

    private function assertVolatileTablesEmpty(): void
    {
        foreach (['password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Volatile table {$table} must not survive restore/recovery.");
        }
    }

    private function resetLifecycleFilesystem(): void
    {
        File::delete($this->installationLockPath);
        File::deleteDirectory($this->restoreRoot);
        File::deleteDirectory($this->privateUploads);
        File::deleteDirectory($this->publicUploads);
        File::ensureDirectoryExists($this->restoreUploads);
        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
    }

    private function writeDestinationEnvironment(string $key): void
    {
        File::put($this->environmentPath, implode("\n", [
            '# destination environment',
            "APP_KEY={$key}",
            'APP_LOCALE=de',
            'APP_FALLBACK_LOCALE=en',
            'SELF_HOST_REGISTRATION=false',
            'APP_URL=https://destination.example.test',
            'DB_CONNECTION=mariadb',
            'DB_HOST='.$this->connectedValue('DESTINATION', 'HOST'),
            'DB_PORT='.$this->connectedValue('DESTINATION', 'PORT'),
            'DB_DATABASE='.$this->connectedValue('DESTINATION', 'DATABASE'),
            'DB_USERNAME='.$this->connectedValue('DESTINATION', 'USERNAME'),
            'DB_PASSWORD='.$this->connectedValue('DESTINATION', 'PASSWORD'),
            'MAIL_PASSWORD=destination-mail-secret',
            'EXTERNAL_API_TOKEN=destination-api-secret',
            '',
        ]));
    }

    private function configureConnectedDatabases(): void
    {
        $base = config('database.connections.mariadb');
        $this->assertIsArray($base);

        foreach (['SOURCE' => 'backup_source', 'DESTINATION' => 'backup_destination'] as $prefix => $name) {
            config(["database.connections.{$name}" => array_merge($base, [
                'url' => null,
                'host' => $this->connectedValue($prefix, 'HOST'),
                'port' => (int) $this->connectedValue($prefix, 'PORT'),
                'database' => $this->connectedValue($prefix, 'DATABASE'),
                'username' => $this->connectedValue($prefix, 'USERNAME'),
                'password' => $this->connectedValue($prefix, 'PASSWORD'),
            ])]);
            DB::purge($name);
        }
    }

    private function migrateFresh(string $connection): void
    {
        DB::purge($connection);
        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
        DB::purge($connection);
    }

    private function useDatabase(string $connection): void
    {
        config(['database.default' => $connection]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);
        $this->assertSame('mariadb', DB::connection()->getDriverName());
    }

    private function dropAllTables(string $connection): void
    {
        DB::purge($connection);
        $schema = DB::connection($connection)->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        foreach ($schema->getTableListing() as $table) {
            $table = (string) $table;
            if (str_contains($table, '.')) {
                $table = substr($table, strrpos($table, '.') + 1);
            }
            $schema->drop($table);
        }

        $schema->enableForeignKeyConstraints();
        DB::purge($connection);
    }

    private function connectedValue(string $prefix, string $key): string
    {
        $value = (string) getenv("BACKUP_{$prefix}_DB_{$key}");
        if ($value === '') {
            $this->fail("Missing BACKUP_{$prefix}_DB_{$key} for connected backup/restore acceptance.");
        }

        return $value;
    }

    private function encrypter(string $key): Encrypter
    {
        $decoded = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;
        $this->assertIsString($decoded);

        return new Encrypter($decoded, (string) config('app.cipher', 'AES-256-CBC'));
    }
}
