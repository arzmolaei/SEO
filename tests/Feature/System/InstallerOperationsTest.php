<?php

namespace Tests\Feature\System;

use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use App\Support\Operations\OperationalHealth;
use App\Support\Operations\QueueProbe;
use App\Support\Operations\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallerOperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private InstallerJournal $journal;

    private SchedulerHeartbeat $heartbeat;

    private QueueProbe $queueProbe;

    /** @var list<string> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-operations-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
            'installer.scheduler_heartbeat_path' => $this->directory.'/scheduler-heartbeat.json',
            'installer.queue_probe_path' => $this->directory.'/queue-probes',
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->heartbeat = new SchedulerHeartbeat($this->directory.'/scheduler-heartbeat.json', 120);
        $this->queueProbe = new QueueProbe(
            $this->directory.'/queue-probes',
            function (string $id): void {
                $this->dispatched[] = $id;
            },
            300,
        );

        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->app->instance(InstallerMutex::class, new InstallerMutex($this->directory.'/installer.lock'));
        $this->bindOperations($this->heartbeat, $this->queueProbe);

        $this->journal->replace([
            'phase' => 'operations',
            'database_fingerprint' => str_repeat('a', 64),
            'migrations_completed_at' => '2026-10-22T20:00:00+00:00',
            'owner_user_id' => 1,
            'owner_created_at' => '2026-10-22T20:01:00+00:00',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_operations_phase_exposes_evidence_actions_and_shared_host_instructions_without_advancing_on_get(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('installer.phase', 'operations')
                ->where('installer.actions', ['dispatch_queue_probe', 'continue_operations'])
                ->where('operations.scheduler.state', 'warning')
                ->where('operations.queue.state', 'warning')
                ->where('operations.blocking', false)
                ->where('operations.instructions.scheduler', fn (string $command) => str_contains($command, 'schedule:run'))
                ->where('operations.instructions.worker', fn (string $command) => str_contains($command, 'queue:work database')));

        $this->assertSame('operations', $this->journal->read()['phase']);
    }

    public function test_queue_probe_action_records_only_non_secret_probe_evidence_and_is_retryable(): void
    {
        $this->post('/install/operations/probe')
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $journal = $this->journal->read();
        $probeId = $journal['queue_probe_id'] ?? null;

        $this->assertIsString($probeId);
        $this->assertSame([$probeId], $this->dispatched);
        $this->assertSame('operations', $journal['phase']);
        $this->assertArrayHasKey('queue_probe_dispatched_at', $journal);
        $this->assertStringNotContainsString('password', strtolower(json_encode($journal, JSON_THROW_ON_ERROR)));

        $this->post('/install/operations/probe')
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $this->dispatched);
        $this->assertNotSame($probeId, $this->journal->read()['queue_probe_id']);
    }

    public function test_verified_scheduler_and_queue_evidence_advances_to_finalize_without_warning_acknowledgement(): void
    {
        $this->heartbeat->record();
        $this->post('/install/operations/probe')->assertRedirect('/install');
        $probeId = $this->journal->read()['queue_probe_id'];
        $this->assertIsString($probeId);
        $this->queueProbe->complete($probeId);

        $this->post('/install/operations/continue', ['acknowledge_warnings' => false])
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $journal = $this->journal->read();
        $this->assertSame('finalize', $journal['phase']);
        $this->assertArrayHasKey('operations_verified_at', $journal);
        $this->assertArrayNotHasKey('operations_warnings_acknowledged_at', $journal);
    }

    public function test_unobserved_external_execution_requires_explicit_warning_acknowledgement(): void
    {
        $this->from('/install')->post('/install/operations/continue', ['acknowledge_warnings' => false])
            ->assertRedirect('/install')
            ->assertSessionHasErrors('operations');

        $this->assertSame('operations', $this->journal->read()['phase']);

        $this->post('/install/operations/continue', ['acknowledge_warnings' => true])
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $journal = $this->journal->read();
        $this->assertSame('finalize', $journal['phase']);
        $this->assertArrayHasKey('operations_warnings_acknowledged_at', $journal);
    }

    public function test_blocking_operational_failure_cannot_be_acknowledged_into_success(): void
    {
        File::put($this->directory.'/blocked', 'not-a-directory');
        $blockedHeartbeat = new SchedulerHeartbeat($this->directory.'/blocked/heartbeat.json', 120);
        $this->bindOperations($blockedHeartbeat, $this->queueProbe);

        $this->from('/install')->post('/install/operations/continue', ['acknowledge_warnings' => true])
            ->assertRedirect('/install')
            ->assertSessionHasErrors('operations');

        $journal = $this->journal->read();
        $this->assertSame('operations', $journal['phase']);
        $this->assertArrayNotHasKey('operations_warnings_acknowledged_at', $journal);
    }

    private function bindOperations(SchedulerHeartbeat $heartbeat, QueueProbe $queueProbe): void
    {
        $this->app->instance(SchedulerHeartbeat::class, $heartbeat);
        $this->app->instance(QueueProbe::class, $queueProbe);
        $this->app->instance(
            OperationalHealth::class,
            new OperationalHealth($heartbeat, $queueProbe, '/usr/bin/php', base_path('artisan')),
        );
    }
}
