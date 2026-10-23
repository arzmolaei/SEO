<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\Backup\CreateSystemBackup;
use App\Services\Backup\RestoreJournal;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SystemBackupController extends Controller
{
    public function edit(RestoreJournal $restoreJournal): InertiaResponse
    {
        $state = $restoreJournal->state();
        $phase = is_string($state['phase'] ?? null)
            ? $state['phase']
            : 'idle';

        return Inertia::render('settings/backup-restore', [
            'restore' => [
                'phase' => $phase,
                'recoveryRequired' => $phase === 'recovery_required',
                'preview' => $phase === 'verified'
                    ? $this->safePreview($state['preview'] ?? null)
                    : null,
            ],
        ]);
    }

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

    /** @return array<string, int|string>|null */
    private function safePreview(mixed $preview): ?array
    {
        if (! is_array($preview)) {
            return null;
        }

        $digest = $preview['digest'] ?? null;
        $sourceInstallationId = $preview['source_installation_id'] ?? null;
        $sourceCreatedAt = $preview['source_created_at_utc'] ?? null;
        $sourceProductVersion = $preview['source_product_version'] ?? null;
        $tableRecordCount = $preview['table_record_count'] ?? null;
        $durableFileCount = $preview['durable_file_count'] ?? null;
        $bundleBytes = $preview['bundle_bytes'] ?? null;

        if (
            ! is_string($digest)
            || ! is_string($sourceInstallationId)
            || ! is_string($sourceCreatedAt)
            || ! is_string($sourceProductVersion)
            || ! is_int($tableRecordCount)
            || ! is_int($durableFileCount)
            || ! is_int($bundleBytes)
        ) {
            return null;
        }

        return [
            'digest' => $digest,
            'source_installation_id' => $sourceInstallationId,
            'source_created_at_utc' => $sourceCreatedAt,
            'source_product_version' => $sourceProductVersion,
            'table_record_count' => $tableRecordCount,
            'durable_file_count' => $durableFileCount,
            'bundle_bytes' => $bundleBytes,
        ];
    }
}
