<?php

namespace App\Providers;

use App\Contracts\SystemProbe;
use App\Domain\Keywords\KeywordRowReader;
use App\Domain\Keywords\StreamingCsvReader;
use App\Services\Update\UpdateJournal;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\RuntimeReleaseAdmission;
use App\Support\Queue\LifecycleDatabaseConnector;
use App\Support\NativeSystemProbe;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KeywordRowReader::class, StreamingCsvReader::class);
        $this->app->bind(SystemProbe::class, NativeSystemProbe::class);
        $this->app->bind(RuntimeReleaseAdmission::class, static fn () => new RuntimeReleaseAdmission);
        $this->callAfterResolving('queue', function (QueueManager $queue): void {
            $queue->addConnector('database', fn () => new LifecycleDatabaseConnector(
                $this->app->make('db'),
                $this->app->make(LifecycleMutex::class),
                $this->app->make(UpdateJournal::class),
            ));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        RateLimiter::for('installer', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip().'|'.$request->path()));

        RateLimiter::for('backup-restore', fn (Request $request): Limit => Limit::perMinute(3)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('update-verify', fn (Request $request): Limit => Limit::perMinute(3)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
