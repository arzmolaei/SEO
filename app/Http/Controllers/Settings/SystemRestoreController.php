<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\Backup\RecoverInterruptedRestore;
use App\Services\Backup\RestoreSystemBackup;
use App\Services\Backup\VerifyRestoreBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class SystemRestoreController extends Controller
{
    public function verify(Request $request, VerifyRestoreBundle $verifyRestoreBundle): JsonResponse
    {
        $upload = $request->file('backup');
        if (! $upload instanceof UploadedFile) {
            return $this->failure(new BackupException(
                'restore_invalid_bundle',
                'فایل پشتیبان معتبر نیست.',
            ));
        }

        try {
            $preview = $verifyRestoreBundle->handle($upload);
        } catch (BackupException $exception) {
            return $this->failure($exception);
        }

        return $this->success([
            'preview' => $preview->toArray(),
        ]);
    }

    public function confirm(Request $request, RestoreSystemBackup $restoreSystemBackup): JsonResponse
    {
        $digest = $request->input('content_digest_sha256');

        if (! is_string($digest)) {
            return $this->failure(new BackupException(
                'restore_digest_mismatch',
                'فایل پشتیبان تأییدشده با درخواست بازیابی مطابقت ندارد.',
            ));
        }

        try {
            $restoreSystemBackup->handle($digest);
        } catch (BackupException $exception) {
            return $this->failure($exception);
        }

        $this->requireFreshLogin($request);

        return $this->success([
            'status' => 'complete',
            'login_required' => true,
        ]);
    }

    public function recover(Request $request, RecoverInterruptedRestore $recoverInterruptedRestore): JsonResponse
    {
        try {
            $recoverInterruptedRestore->handle();
        } catch (BackupException $exception) {
            return $this->failure($exception);
        }

        $this->requireFreshLogin($request);

        return $this->success([
            'status' => 'recovered',
            'login_required' => true,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function success(array $payload): JsonResponse
    {
        return response()->json($payload, 200, $this->privateHeaders());
    }

    private function failure(BackupException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->codeName(),
            'message' => $exception->userMessage(),
        ], 422, $this->privateHeaders());
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

    private function requireFreshLogin(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
