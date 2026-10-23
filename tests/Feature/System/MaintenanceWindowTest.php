<?php

namespace Tests\Feature\System;

use App\Support\Lifecycle\MaintenanceWindow;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;
use Tests\TestCase;

class MaintenanceWindowTest extends TestCase
{
    private MaintenanceMode $maintenanceMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maintenanceMode = $this->app->maintenanceMode();
        $this->maintenanceMode->deactivate();
    }

    protected function tearDown(): void
    {
        $this->maintenanceMode->deactivate();

        parent::tearDown();
    }

    public function test_it_enters_laravel_maintenance_before_callback_and_leaves_after_success(): void
    {
        $window = new MaintenanceWindow($this->maintenanceMode);

        $result = $window->run('backup', function (): string {
            $this->assertTrue($this->maintenanceMode->active());
            $data = $this->maintenanceMode->data();
            $this->assertSame('seo-os-lifecycle', $data['seo_os_owner']);
            $this->assertSame('backup', $data['seo_os_operation']);
            $this->assertNotEmpty($data['seo_os_owner_token']);

            return 'captured';
        });

        $this->assertSame('captured', $result);
        $this->assertFalse($this->maintenanceMode->active());
    }

    public function test_it_leaves_its_own_maintenance_mode_when_callback_throws(): void
    {
        $window = new MaintenanceWindow($this->maintenanceMode);

        try {
            $window->run('restore', static function (): never {
                throw new RuntimeException('restore failed');
            });

            $this->fail('Expected callback failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('restore failed', $exception->getMessage());
        }

        $this->assertFalse($this->maintenanceMode->active());
    }

    public function test_callback_failure_is_not_suppressed_if_callback_deactivates_maintenance(): void
    {
        $window = new MaintenanceWindow($this->maintenanceMode);

        try {
            $window->run('restore', function (): never {
                $this->maintenanceMode->deactivate();

                throw new RuntimeException('restore failed after maintenance exit');
            });

            $this->fail('Expected callback failure was suppressed by maintenance cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('restore failed after maintenance exit', $exception->getMessage());
        }

        $this->assertFalse($this->maintenanceMode->active());
    }

    public function test_it_never_clears_unrelated_administrator_maintenance_state(): void
    {
        $this->maintenanceMode->activate([
            'time' => time(),
            'retry' => 120,
            'message' => 'administrator maintenance',
        ]);
        $window = new MaintenanceWindow($this->maintenanceMode);

        try {
            $window->run('backup', static fn (): null => null);
            $this->fail('Lifecycle maintenance must not replace administrator maintenance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Application is already in administrator maintenance mode.', $exception->getMessage());
        }

        $this->assertTrue($this->maintenanceMode->active());
        $this->assertSame('administrator maintenance', $this->maintenanceMode->data()['message']);
    }

    public function test_it_recovers_stale_lifecycle_owned_maintenance_before_entering_a_fresh_window(): void
    {
        $this->maintenanceMode->activate([
            'time' => time() - 3600,
            'retry' => 60,
            'seo_os_owner' => 'seo-os-lifecycle',
            'seo_os_operation' => 'restore',
            'seo_os_owner_token' => 'stale-owner-token',
        ]);
        $window = new MaintenanceWindow($this->maintenanceMode);

        $window->run('backup', function (): void {
            $data = $this->maintenanceMode->data();

            $this->assertSame('backup', $data['seo_os_operation']);
            $this->assertNotSame('stale-owner-token', $data['seo_os_owner_token']);
        });

        $this->assertFalse($this->maintenanceMode->active());
    }

    public function test_cleanup_does_not_disable_maintenance_if_ownership_changes_during_callback(): void
    {
        $window = new MaintenanceWindow($this->maintenanceMode);

        $window->run('backup', function (): void {
            $this->maintenanceMode->deactivate();
            $this->maintenanceMode->activate([
                'time' => time(),
                'retry' => 60,
                'message' => 'administrator took ownership',
            ]);
        });

        $this->assertTrue($this->maintenanceMode->active());
        $this->assertSame('administrator took ownership', $this->maintenanceMode->data()['message']);
    }
}
