<?php

namespace App\Http\Middleware;

use App\Services\Backup\RestoreJournal;
use App\Services\Update\UpdateJournal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureNoConflictingLifecycleRecovery
{
    public function __construct(
        private readonly UpdateJournal $updateJournal,
        private readonly RestoreJournal $restoreJournal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($this->updateJournal->state() !== null || $this->restoreJournal->hasActiveOperation()) {
                return $this->conflict();
            }
        } catch (Throwable) {
            return $this->conflict();
        }

        return $next($request);
    }

    private function conflict(): JsonResponse
    {
        return response()->json([
            'code' => 'update_lifecycle_conflict',
            'message' => 'یک عملیات بازیابی یا به‌روزرسانی دیگر باید ابتدا تعیین تکلیف شود.',
        ], 409, $this->privateHeaders());
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
