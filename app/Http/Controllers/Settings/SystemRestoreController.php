<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\Backup\VerifyRestoreBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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

        return response()->json([
            'preview' => $preview->toArray(),
        ]);
    }

    private function failure(BackupException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->codeName(),
            'message' => $exception->userMessage(),
        ], 422, [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
