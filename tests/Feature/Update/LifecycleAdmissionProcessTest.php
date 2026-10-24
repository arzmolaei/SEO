<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateQuiescence;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\RuntimeReleaseAdmission;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\LifecycleProbeJob;
use Tests\TestCase;

class LifecycleAdmissionProcessTest extends TestCase
{
    private string $directory;

    private string $installationRoot;

    private ActiveReleasePointer $pointer;

    private LifecycleMutex $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-admission-process-'.bin2hex(random_bytes(6));
        $this->installationRoot = $this->directory.'/installation';
        File::ensureDirectoryExists($this->installationRoot, 0700);
        File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.1\n");
        File::ensureDirectoryExists($this->installationRoot.'/bootstrap', 0700);
        File::copy(base_path('bootstrap/runtime-release.php'), $this->installationRoot.'/bootstrap/runtime-release.php');
        $this->pointer = new ActiveReleasePointer($this->installationRoot);
        $this->mutex = new LifecycleMutex($this->directory.'/lifecycle.lock', new RuntimeReleaseAdmission($this->pointer, $this->installationRoot));
        config([
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle.lock',
            'database.connections.admission_process' => array_replace(config('database.connections.sqlite'), ['database' => $this->directory.'/queue.sqlite']),
            'queue.connections.database.connection' => 'admission_process',
            'queue.default' => 'database',
        ]);
        File::put($this->directory.'/queue.sqlite', '');
        DB::connection('admission_process')->getSchemaBuilder()->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('admission_process');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_real_paused_http_mutation_must_finish_before_safety_capture_can_acquire_ownership(): void
    {
        $process = $this->startChild('request', false);
        try {
            $this->waitReady($process);
            try {
                $unexpected = $this->mutex->acquire('update');
                $unexpected->release();
                $this->fail('Safety capture must wait for the already-admitted request.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Another lifecycle operation is already running.', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($this->directory.'/mutated');
            File::put($this->directory.'/continue', 'go');
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('200', $process->getOutput());
            $ownership = $this->mutex->acquire('update');
            try {
                $this->assertSame('executed', File::get($this->directory.'/mutated'));
            } finally {
                $ownership->release();
            }
        } finally {
            $process->stop(1);
        }
    }

    /** @return array<string, array{string}> */
    public static function staleSurfaces(): array
    {
        return ['HTTP request' => ['request'], 'idle worker' => ['worker']];
    }

    #[DataProvider('staleSurfaces')]
    public function test_a_paused_source_process_cannot_mutate_after_a_completed_pointer_switch(string $surface): void
    {
        Queue::connection('database')->push(new LifecycleProbeJob($this->directory.'/mutated'));
        $process = $this->startChild($surface, true);
        try {
            $this->waitReady($process);
            $ownership = $this->mutex->acquire('update');
            try {
                $this->activateTarget();
            } finally {
                $ownership->release();
            }
            // Maintenance has cleared and no lifecycle owner remains. Identity
            // must still reject the old process before controller/reservation.
            File::put($this->directory.'/continue', 'go');
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($surface === 'request' ? '503' : 'idle', $process->getOutput());
            $this->assertFileDoesNotExist($this->directory.'/mutated');
            $this->assertSame(1, DB::connection('admission_process')->table('jobs')->count());
            $this->assertNull(DB::connection('admission_process')->table('jobs')->value('reserved_at'));
        } finally {
            $process->stop(1);
        }
    }

    public function test_a_real_idle_worker_cannot_cross_the_quiescent_exclusive_snapshot_boundary(): void
    {
        Queue::connection('database')->push(new LifecycleProbeJob($this->directory.'/mutated'));
        $process = $this->startChild('worker', true);
        try {
            $this->waitReady($process);
            $ownership = $this->mutex->acquire('update');
            try {
                $this->app->make(UpdateQuiescence::class)->assertReady();
                File::put($this->directory.'/continue', 'go');
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('idle', $process->getOutput());
                $this->assertFileDoesNotExist($this->directory.'/mutated');
                $this->assertNull(DB::connection('admission_process')->table('jobs')->value('reserved_at'));
            } finally {
                $ownership->release();
            }
        } finally {
            $process->stop(1);
        }
    }

    private function activateTarget(): void
    {
        $id = 'target-probe';
        $slot = $this->installationRoot.'/storage/app/lifecycle/update/releases/'.$id;
        File::ensureDirectoryExists($slot, 0700);
        File::put($slot.'/VERSION', "0.1.0-alpha.2\n");
        File::put($slot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1', 'release_id' => $id,
            'product_version' => '0.1.0-alpha.2', 'package_digest_sha256' => str_repeat('a', 64),
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR));
        $this->pointer->activateSlot($id, '0.1.0-alpha.2', str_repeat('a', 64));
    }

    private function waitReady(Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/ready') && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->directory.'/ready', $process->getErrorOutput());
    }

    private function startChild(string $surface, bool $pauseBeforeAdmission): Process
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);
        $launcher = var_export($this->installationRoot.'/bootstrap/runtime-release.php', true);
        $directory = var_export($this->directory, true);
        $installationRoot = var_export($this->installationRoot, true);
        $database = var_export(config('database.connections.admission_process'), true);
        $pause = <<<'PAUSE'
file_put_contents($directory.'/ready', 'ready');
$deadline = microtime(true) + 10;
while (! is_file($directory.'/continue')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Fixture timed out.'); }
    usleep(10000);
}
PAUSE;
        $before = $pauseBeforeAdmission ? $pause : '';
        $inside = $pauseBeforeAdmission ? '' : $pause;
        $action = $surface === 'worker' ? <<<'WORKER'
$app->make('queue.worker')->runNextJob('database', 'default', new Illuminate\Queue\WorkerOptions(sleep: 0));
echo 'idle';
WORKER : <<<'REQUEST'
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/paused-mutation', 'POST'));
echo $response->getStatusCode();
$kernel->terminate(Illuminate\Http\Request::create('/paused-mutation', 'POST'), $response);
REQUEST;
        File::put($this->directory.'/child.php', <<<PHPCHILD
<?php
require {$autoload};
require_once {$launcher};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$directory = {$directory};
\$installationRoot = {$installationRoot};
config(['installer.assume_installed' => true, 'session.driver' => 'array', 'cache.default' => 'array', 'database.connections.admission_process' => {$database}, 'queue.default' => 'database', 'queue.connections.database.connection' => 'admission_process', 'backup.lifecycle_mutex_path' => \$directory.'/lifecycle.lock']);
\$app->instance(App\Support\Lifecycle\RuntimeReleaseAdmission::class, new App\Support\Lifecycle\RuntimeReleaseAdmission(new App\Support\Update\ActiveReleasePointer(\$installationRoot), \$installationRoot));
Illuminate\Support\Facades\Route::post('/paused-mutation', function () use (\$directory) {
{$inside}
    file_put_contents(\$directory.'/mutated', 'executed');
    return response('done');
})->middleware('web');
{$before}
{$action}
PHPCHILD);
        $process = new Process([PHP_BINARY, $this->directory.'/child.php'], base_path(), timeout: 15);
        $process->start();

        return $process;
    }
}
