<?php

namespace Tests\Unit\System;

use App\Support\Lifecycle\LifecycleMutex;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LifecycleMutexTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/seo-os-lifecycle-mutex-'.bin2hex(random_bytes(6));
        $this->path = $this->directory.'/lifecycle.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_lock_is_exclusive_non_blocking_and_records_only_non_secret_operation_metadata(): void
    {
        $mutex = new LifecycleMutex($this->path);
        $handle = $mutex->acquire('backup');

        $this->assertTrue($handle->isActive());
        $this->assertSame('backup', $handle->operation());
        $this->assertSame(0600, fileperms($this->path) & 0777);

        $metadata = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('backup', $metadata['operation']);
        $this->assertArrayHasKey('started_at', $metadata);
        $this->assertArrayHasKey('owner_token', $metadata);
        $this->assertArrayNotHasKey('app_key', $metadata);
        $this->assertArrayNotHasKey('password', $metadata);

        $started = hrtime(true);

        try {
            $mutex->acquire('restore');
            $this->fail('A second lifecycle operation acquired an already-held mutex.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another lifecycle operation is already running.', $exception->getMessage());
            $this->assertLessThan(500_000_000, hrtime(true) - $started);
        } finally {
            $handle->release();
        }
    }

    public function test_active_handle_proves_its_actual_lock_path_without_exposing_the_path(): void
    {
        $handle = (new LifecycleMutex($this->path))->acquire('update');
        $this->assertSame(hash('sha256', realpath($this->path)), $handle->pathFingerprint());
        $handle->release();
        $this->expectException(RuntimeException::class);
        $handle->pathFingerprint();
    }

    public function test_release_is_idempotent_and_allows_a_later_operation_to_acquire(): void
    {
        $mutex = new LifecycleMutex($this->path);
        $first = $mutex->acquire('backup');

        $first->release();
        $first->release();

        $this->assertFalse($first->isActive());

        $second = $mutex->acquire('restore');
        $this->assertTrue($second->isActive());
        $this->assertSame('restore', $second->operation());
        $second->release();
    }

    public function test_stale_metadata_without_an_os_lock_does_not_block_a_new_owner(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->path, json_encode([
            'operation' => 'stale-restore',
            'started_at' => '2000-01-01T00:00:00+00:00',
            'owner_token' => 'dead-owner',
        ], JSON_THROW_ON_ERROR));

        $handle = (new LifecycleMutex($this->path))->acquire('backup');
        $metadata = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('backup', $metadata['operation']);
        $this->assertNotSame('dead-owner', $metadata['owner_token']);

        $handle->release();
    }

    public function test_same_active_handle_can_be_passed_to_nested_work_without_recursive_reacquire(): void
    {
        $handle = (new LifecycleMutex($this->path))->acquire('restore');

        $nested = static function ($ownership): string {
            $ownership->assertActive();

            return $ownership->operation();
        };

        $this->assertSame('restore', $nested($handle));

        $handle->release();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lifecycle mutex ownership is no longer active.');
        $handle->assertActive();
    }

    public function test_shared_request_leases_coexist_and_exclude_lifecycle_mutation_until_all_are_released(): void
    {
        $mutex = new LifecycleMutex($this->path);
        $first = $mutex->acquireReadLease();
        $second = $mutex->acquireReadLease();
        $first->release();
        try {
            $unexpected = $mutex->acquire('update');
            $unexpected->release();
            $this->fail('A remaining request lease must exclude destructive ownership.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another lifecycle operation is already running.', $exception->getMessage());
        } finally {
            $second->release();
        }
        $ownership = $mutex->acquire('update');
        $ownership->release();
    }

    public function test_exclusive_owner_waits_for_a_transient_lease_from_another_process_to_drain(): void
    {
        mkdir($this->directory, 0700, true);
        $ready = $this->directory.'/ready';
        // A request or worker reservation in another process: a short shared lease.
        $process = new Process([PHP_BINARY, '-r', sprintf(
            '$f = fopen(%s, "c+"); flock($f, LOCK_SH); file_put_contents(%s, "1"); usleep(300000); flock($f, LOCK_UN);',
            var_export($this->path, true),
            var_export($ready, true),
        )]);
        $process->start();

        try {
            $deadline = microtime(true) + 10;
            while (! is_file($ready) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($ready, $process->getErrorOutput());

            $handle = (new LifecycleMutex($this->path))->acquire('restore');
            $this->assertTrue($handle->isActive());
            $this->assertSame('restore', $handle->operation());
            $handle->release();
        } finally {
            $process->stop(1);
        }
    }

    public function test_an_exclusive_owner_is_still_refused_immediately_while_leases_wait(): void
    {
        $mutex = new LifecycleMutex($this->path);
        $handle = $mutex->acquire('update');
        $started = hrtime(true);

        try {
            $mutex->acquireReadLease();
            $this->fail('A request lease must not enter during an exclusive operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another lifecycle operation is already running.', $exception->getMessage());
            $this->assertLessThan(500_000_000, hrtime(true) - $started);
        } finally {
            $handle->release();
        }
    }
}
