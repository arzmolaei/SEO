<?php

namespace Tests\Feature\Update;

use App\Jobs\ProcessKeywordImportFile;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateQuiescence;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class LifecycleDatabaseQueueTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-queue-admission-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config(['backup.lifecycle_mutex_path' => $this->directory.'/lifecycle.lock', 'queue.default' => 'database']);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->directory.'/state.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_actual_database_connector_cannot_reserve_after_quiescence_while_update_owns_the_lock(): void
    {
        $id = Queue::connection('database')->pushRaw('{"job":"probe"}');
        $ownership = (new LifecycleMutex)->acquire('update');
        try {
            $this->app->make(UpdateQuiescence::class)->assertReady();
            $this->assertTrue(Queue::connection('database')->pop() === null, 'Reservation admission must return no job.');
            $this->assertNull(DB::table('jobs')->where('id', $id)->value('reserved_at'));
        } finally {
            $ownership->release();
        }
        $job = Queue::connection('database')->pop();
        $this->assertInstanceOf(DatabaseJob::class, $job);
        $this->assertNotNull(DB::table('jobs')->where('id', $id)->value('reserved_at'));
        try {
            $this->app->make(UpdateQuiescence::class)->assertReady();
            $this->fail('A reservation committed before admission closed must block safety capture.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_quiescence_required', $exception->codeName());
        }
        $job->delete();
        $this->app->make(UpdateQuiescence::class)->assertReady();
    }

    public function test_reservation_excludes_lifecycle_operations_but_not_ordinary_request_admission(): void
    {
        Queue::connection('database')->pushRaw('{"job":"probe"}');
        $observed = [];
        DB::listen(function ($query) use (&$observed): void {
            if (isset($observed['request']) || ! str_contains(strtolower($query->sql), 'jobs')) {
                return;
            }
            try {
                (new LifecycleMutex)->acquireReadLease()->release();
                $observed['request'] = true;
            } catch (RuntimeException) {
                $observed['request'] = false;
            }
            try {
                (new LifecycleMutex)->acquire('update')->release();
                $observed['lifecycle'] = true;
            } catch (RuntimeException) {
                $observed['lifecycle'] = false;
            }
        });

        $this->assertInstanceOf(DatabaseJob::class, Queue::connection('database')->pop());

        // A worker polling the queue must never turn user requests into 503s...
        $this->assertTrue($observed['request'] ?? null, 'HTTP admission must coexist with a queue reservation.');
        // ...while a lifecycle operation still cannot start mid-reservation.
        $this->assertFalse($observed['lifecycle'] ?? null, 'Lifecycle ownership must wait for the reservation.');
    }

    public function test_database_reservation_does_not_expire_while_import_worker_may_still_be_running(): void
    {
        $queue = app('queue')->connection('database');
        $queue->push(new ProcessKeywordImportFile(1));
        $first = $queue->pop();
        $this->assertNotNull($first);
        DB::table('jobs')->update(['reserved_at' => now()->timestamp - 100]);
        $this->assertTrue($queue->pop() === null, 'A second worker must not reserve a live 600-second import after only 100 seconds.');
    }

    public function test_idle_worker_cannot_reserve_during_persistent_maintenance_between_update_requests(): void
    {
        $id = Queue::connection('database')->pushRaw('{"job":"probe"}');
        File::put($this->directory.'/state.json', json_encode(['phase' => 'migrating'], JSON_THROW_ON_ERROR));
        $this->assertTrue(Queue::connection('database')->pop() === null, 'Reservation admission must return no job.');
        $this->assertNull(DB::table('jobs')->where('id', $id)->value('reserved_at'));
        File::delete($this->directory.'/state.json');
        $this->assertInstanceOf(DatabaseJob::class, Queue::connection('database')->pop());
    }

    public function test_reservation_rejects_an_ambient_transaction_before_it_can_hide_the_reservation_commit(): void
    {
        $id = Queue::connection('database')->pushRaw('{"job":"probe"}');
        DB::beginTransaction();
        try {
            try {
                Queue::connection('database')->pop();
                $this->fail('Reservation inside an ambient transaction must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Queue reservation requires an independent transaction.', $exception->getMessage());
            }
            $this->assertNull(DB::table('jobs')->where('id', $id)->value('reserved_at'));
        } finally {
            DB::rollBack();
        }
    }
}
