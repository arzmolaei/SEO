<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\Backup\CreateSystemBackup;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SystemBackupController extends Controller
{
    public function download(CreateSystemBackup $createSystemBackup): StreamedResponse|Response
    {
        try {
            $result = $createSystemBackup->handle();
        } catch (BackupException $exception) {
            return response($exception->userMessage(), 503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-SEO-Backup-Error' => $exception->codeName(),
            ]);
        }

        return response()->streamDownload(
            function () use ($result): void {
                try {
                    fpassthru($result->stream());
                } finally {
                    $result->close();
                }
            },
            $result->filename(),
            [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-SEO-Backup-Sensitive' => 'true',
            ],
        );
    }
}
