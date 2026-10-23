<?php

namespace App\Support\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManifest;

final class BackupBundleWriter
{
    /** @var array{max_manifest_bytes:int,max_path_bytes:int,max_entries:int,max_entry_bytes:int,max_bundle_bytes:int,chunk_bytes:int} */
    private array $limits;

    /** @param array<string, int>|null $limits */
    public function __construct(?array $limits = null)
    {
        $defaults = (array) config('backup.bundle', []);
        $this->limits = [
            'max_manifest_bytes' => (int) ($limits['max_manifest_bytes'] ?? $defaults['max_manifest_bytes'] ?? 4 * 1024 * 1024),
            'max_path_bytes' => (int) ($limits['max_path_bytes'] ?? $defaults['max_path_bytes'] ?? 1024),
            'max_entries' => (int) ($limits['max_entries'] ?? $defaults['max_entries'] ?? 100_000),
            'max_entry_bytes' => (int) ($limits['max_entry_bytes'] ?? $defaults['max_entry_bytes'] ?? 1024 * 1024 * 1024),
            'max_bundle_bytes' => (int) ($limits['max_bundle_bytes'] ?? $defaults['max_bundle_bytes'] ?? 2 * 1024 * 1024 * 1024),
            'chunk_bytes' => max(1, (int) ($limits['chunk_bytes'] ?? $defaults['chunk_bytes'] ?? 64 * 1024)),
        ];
    }

    /**
     * @param  resource  $output
     * @param  array<string, mixed>  $manifestFields
     * @param  list<array{path:string,stream:resource,media_type:string,record_count?:int}>  $entries
     */
    public function write($output, array $manifestFields, array $entries): BackupManifest
    {
        $this->assertStream($output);

        if (count($entries) > $this->limits['max_entries']) {
            throw $this->integrityFailure();
        }

        if (array_key_exists('entries', $manifestFields) || array_key_exists('content_digest_sha256', $manifestFields)) {
            throw $this->integrityFailure();
        }

        $metadata = [];
        $paths = [];

        foreach ($entries as $entry) {
            $path = $entry['path'];
            BackupBundleFormat::assertCapturePath($path);

            if (strlen($path) > $this->limits['max_path_bytes'] || isset($paths[$path])) {
                throw $this->integrityFailure();
            }
            $paths[$path] = true;

            $stream = $entry['stream'];
            $this->assertStream($stream);
            $this->rewindStream($stream);

            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, $this->limits['chunk_bytes']);
                if ($chunk === false) {
                    throw $this->integrityFailure();
                }
                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                if ($size > $this->limits['max_entry_bytes']) {
                    throw $this->integrityFailure();
                }
                hash_update($hash, $chunk);
            }

            $item = [
                'path' => $path,
                'size' => $size,
                'sha256' => hash_final($hash),
                'media_type' => $entry['media_type'],
            ];

            if ($item['media_type'] === '') {
                throw $this->integrityFailure();
            }

            if (array_key_exists('record_count', $entry)) {
                if ($entry['record_count'] < 0) {
                    throw $this->integrityFailure();
                }
                $item['record_count'] = $entry['record_count'];
            }

            $metadata[] = $item;
        }

        $contentHash = hash_init('sha256');
        foreach ($metadata as $index => $item) {
            $metadataJson = BackupBundleFormat::canonicalMetadataJson($item);
            hash_update($contentHash, pack('N', strlen($metadataJson)).$metadataJson);

            $stream = $entries[$index]['stream'];
            $this->rewindStream($stream);
            $this->hashStream($stream, $contentHash);
        }

        $manifest = BackupManifest::fromArray([
            ...$manifestFields,
            'entries' => $metadata,
            'content_digest_sha256' => hash_final($contentHash),
        ]);
        $manifestJson = $manifest->canonicalJson();

        if (strlen($manifestJson) > $this->limits['max_manifest_bytes']) {
            throw $this->integrityFailure();
        }

        $bundleSize = strlen(BackupBundleFormat::MAGIC) + 4 + strlen($manifestJson);
        foreach ($metadata as $item) {
            $bundleSize += 4 + strlen($item['path']) + 8 + $item['size'];
            if ($bundleSize > $this->limits['max_bundle_bytes']) {
                throw $this->integrityFailure();
            }
        }

        if (! rewind($output) || ! ftruncate($output, 0)) {
            throw $this->integrityFailure();
        }

        $this->writeAll($output, BackupBundleFormat::MAGIC);
        $this->writeAll($output, pack('N', strlen($manifestJson)));
        $this->writeAll($output, $manifestJson);

        foreach ($metadata as $index => $item) {
            $this->writeAll($output, pack('N', strlen($item['path'])));
            $this->writeAll($output, $item['path']);
            $this->writeAll($output, BackupBundleFormat::packUint64($item['size']));

            $stream = $entries[$index]['stream'];
            $this->rewindStream($stream);
            while (! feof($stream)) {
                $chunk = fread($stream, $this->limits['chunk_bytes']);
                if ($chunk === false) {
                    throw $this->integrityFailure();
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeAll($output, $chunk);
            }
        }

        $this->rewindStream($output);

        return $manifest;
    }

    /** @param resource $stream */
    private function assertStream($stream): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw $this->integrityFailure();
        }

        $metadata = stream_get_meta_data($stream);
        if (($metadata['seekable'] ?? false) !== true) {
            throw $this->integrityFailure();
        }
    }

    /** @param resource $stream */
    private function rewindStream($stream): void
    {
        if (! rewind($stream)) {
            throw $this->integrityFailure();
        }
    }

    /** @param resource $stream @param \HashContext $hash */
    private function hashStream($stream, \HashContext $hash): void
    {
        while (! feof($stream)) {
            $chunk = fread($stream, $this->limits['chunk_bytes']);
            if ($chunk === false) {
                throw $this->integrityFailure();
            }
            if ($chunk === '') {
                break;
            }
            hash_update($hash, $chunk);
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw $this->integrityFailure();
            }
            $offset += $written;
        }
    }

    private function integrityFailure(): BackupException
    {
        return new BackupException(
            'backup_integrity_failed',
            'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
        );
    }
}
