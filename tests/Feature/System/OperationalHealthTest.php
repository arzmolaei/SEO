<?php

namespace Tests\Feature\System;

use App\Support\Operations\OperationalHealth;
use App\Support\Operations\QueueProbe;
use App\Support\Operations\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class OperationalHealthTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 20:00:00');
        $this->directory = storage_path('framework/testing/operations-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_scheduler_is_warning_until_a_fresh_heartbeat_is_observed_and_stale_evidence_returns_to_warning(): void
    {
        $heartbeat = new SchedulerHeartbeat($this->directory.'/scheduler-heartbeat.json', 120);

        $this->assertSame('warning', $heartbeat->status()['state']);
        $this->assertNull($heartbeat->status()['observed_at']);

        $heartbeat->record();

        $fresh = $heartbeat->status();
        $this->assertSame('verified', $fresh['state']);
        $this->assertNotNull($fresh['observed_at']);

        Carbon::setTestNow(now()->addMinutes(3));

        $this->assertSame('warning', $heartbeat->status()['state']);
    }

    public function test_unwritable_scheduler_state_is_a_blocking_failure(): void
    {
        File::put($this->directory.'/blocked', 'not-a-directory');
        $heartbeat = new SchedulerHeartbeat($this->directory.'/blocked/heartbeat.json', 120);

        $this->assertSame('fail', $heartbeat->status()['state']);

        $this->expectException(RuntimeException::class);
        $heartbeat->record();
    }

    public function test_queue_probe_is_warning_while_pending_verified_after_completion_and_warning_after_expiry(): void
    {
        $dispatched = [];
        $probe = new QueueProbe(
            $this->directory.'/queue-probes',
            static function (string $id) use (&$dispatched): void {
                $dispatched[] = $id;
            },
            300,
        );

        $id = $probe->dispatch();

        $this->assertSame([$id], $dispatched);
        $this->assertSame($id, $probe->latestId());
        $this->assertSame('warning', $probe->status($id)['state']);
        $this->assertNull($probe->status($id)['completed_at']);

        $probe->complete($id);

        $completed = $probe->status($id);
        $this->assertSame('verified', $completed['state']);
        $this->assertNotNull($completed['completed_at']);

        Carbon::setTestNow(now()->addMinutes(6));

        $this->assertSame('warning', $probe->status($id)['state']);
    }

    public function test_queue_probe_storage_or_dispatch_failures_are_explicit_failures(): void
    {
        File::put($this->directory.'/blocked-probes', 'not-a-directory');
        $probe = new QueueProbe(
            $this->directory.'/blocked-probes',
            static function (string $id): void {},
            300,
        );

        $this->expectException(RuntimeException::class);
        $probe->dispatch();
    }

    public function test_operational_snapshot_uses_evidence_and_shared_host_commands_without_external_network(): void
    {
        $heartbeat = new SchedulerHeartbeat($this->directory.'/heartbeat.json', 120);
        $probe = new QueueProbe(
            $this->directory.'/probes',
            static function (string $id): void {},
            300,
        );
        $health = new OperationalHealth(
            $heartbeat,
            $probe,
            '/usr/bin/php',
            '/var/www/seo/artisan',
        );

        $snapshot = $health->snapshot();

        $this->assertSame('warning', $snapshot['scheduler']['state']);
        $this->assertSame('warning', $snapshot['queue']['state']);
        $this->assertFalse($snapshot['blocking']);
        $this->assertStringContainsString('/usr/bin/php /var/www/seo/artisan schedule:run', $snapshot['instructions']['scheduler']);
        $this->assertStringContainsString('/usr/bin/php /var/www/seo/artisan queue:work database', $snapshot['instructions']['worker']);

        foreach ([
            app_path('Support/Operations/OperationalHealth.php'),
            app_path('Support/Operations/SchedulerHeartbeat.php'),
            app_path('Support/Operations/QueueProbe.php'),
        ] as $path) {
            $source = File::get($path);
            $this->assertStringNotContainsString('Http::', $source);
            $this->assertStringNotContainsString('Guzzle', $source);
            $this->assertStringNotContainsString('curl_', $source);
        }
    }
}
