<?php

namespace App\Http\Middleware;

use App\Services\Update\UpdateJournal;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureLifecycleRequestAdmission
{
    public function __construct(
        private readonly LifecycleMutex $mutex,
        private readonly UpdateJournal $journal,
        private readonly InstallationState $installationState,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installationState->isInstalled() || $this->ownsExclusiveAdmission($request)) {
            return $next($request);
        }
        try {
            $lease = $this->mutex->acquireReadLease();
        } catch (Throwable) {
            return $this->unavailable();
        }
        try {
            try {
                $state = $this->journal->state();
                if (UpdateJournal::isMaintenancePhase(is_string($state['phase'] ?? null) ? $state['phase'] : null)) {
                    require_once base_path('bootstrap/runtime-release.php');
                    if (! \SeoOsUpdateMaintenanceGate::isRecoveryHttpPath($request->getPathInfo())) {
                        return $this->unavailable();
                    }
                }
            } catch (Throwable) {
                return $this->unavailable();
            }

            return $next($request);
        } finally {
            $lease->release();
        }
    }

    private function ownsExclusiveAdmission(Request $request): bool
    {
        return in_array($request->method().' '.$request->getPathInfo(), [
            'GET /settings/backup/download',
            'HEAD /settings/backup/download',
            'POST /settings/backup/restore/verify',
            'POST /settings/backup/restore/confirm',
            'POST /settings/backup/restore/recover',
            'POST /settings/update/confirm',
            'POST /settings/update/continue',
            'POST /settings/update/recover',
            'POST /settings/update/cancel',
        ], true);
    }

    private function unavailable(): Response
    {
        return response('عملیات نگهداری در حال اجرا است؛ صفحه را دوباره بارگذاری کنید.', 503, [
            'Cache-Control' => 'private, no-store',
            'Retry-After' => '5',
        ]);
    }
}
