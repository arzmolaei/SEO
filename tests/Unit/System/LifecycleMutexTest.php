<?php

namespace Tests\Unit\System;

use App\Support\Lifecycle\LifecycleMutex;
use RuntimeException;
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
}
