<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateQuiescence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateQuiescenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_queue_is_quiescent_and_worker_restart_uses_configured_cache_repository(): void
    {
        config([
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);
        Cache::store('array')->forget('illuminate:queue:restart');

        $quiescence = $this->app->make(UpdateQuiescence::class);
        $quiescence->assertReady();
        $quiescence->requestWorkerRestart();

        $restart = Cache::store('array')->get('illuminate:queue:restart');
        $this->assertIsInt($restart);
        $this->assertGreaterThan(0, $restart);
    }

    public function test_database_queue_allows_unreserved_jobs_but_rejects_reserved_jobs(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
        ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $quiescence = $this->app->make(UpdateQuiescence::class);
        $quiescence->assertReady();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        try {
            $quiescence->assertReady();
            $this->fail('Reserved database queue work must block destructive update preparation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_quiescence_required', $exception->codeName());
        }
    }

    public function test_unsupported_queue_driver_fails_closed(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
        ]);

        try {
            $this->app->make(UpdateQuiescence::class)->assertReady();
            $this->fail('Unsupported production queue drivers must not be guessed as quiescent.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_quiescence_required', $exception->codeName());
        }
    }
}
