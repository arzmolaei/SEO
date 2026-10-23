<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Update\UpdateJournal;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class LifecycleAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-request-admission-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config(['backup.lifecycle_mutex_path' => $this->directory.'/lifecycle.lock']);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->directory.'/state.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_profile_mutation_cannot_start_while_a_lifecycle_operation_owns_the_lock(): void
    {
        $user = User::factory()->create(['name' => 'before']);
        $ownership = (new LifecycleMutex)->acquire('update');
        try {
            $this->actingAs($user)->patch(route('profile.update'), ['name' => 'after', 'email' => $user->email])->assertStatus(503);
        } finally {
            $ownership->release();
        }
        $this->assertSame('before', $user->fresh()->name);
    }

    public function test_an_admitted_request_excludes_safety_capture_until_downstream_mutation_returns(): void
    {
        $blocked = false;
        Route::post('/admission-probe', function () use (&$blocked) {
            try {
                $ownership = (new LifecycleMutex)->acquire('update');
                $ownership->release();
            } catch (RuntimeException) {
                $blocked = true;
            }
            File::put($this->directory.'/mutation-finished', 'done');

            return response('done');
        })->middleware('web');
        $this->post('/admission-probe')->assertOk();
        $this->assertTrue($blocked, 'The request must retain shared admission through downstream mutation.');
        $this->assertFileExists($this->directory.'/mutation-finished');
        $ownership = (new LifecycleMutex)->acquire('update');
        $ownership->release();
    }

    public function test_framework_admission_rechecks_maintenance_after_an_earlier_launcher_check(): void
    {
        $user = User::factory()->create(['name' => 'before']);
        File::put($this->directory.'/state.json', json_encode(['phase' => 'migrating'], JSON_THROW_ON_ERROR));
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'after', 'email' => $user->email])->assertStatus(503);
        $this->assertSame('before', $user->fresh()->name);
    }
}
