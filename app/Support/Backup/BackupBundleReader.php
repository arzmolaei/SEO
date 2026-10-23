<?php

namespace App\Support\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManifest;
use JsonException;

final class BackupBundleReader
{
    /** @var array{max_manifest_bytes:int,max_path_bytes:int,max_entries:int,max_entry_bytes:int,max_bundle_bytes:int,chunk_bytes:int} */
    private array $limits;

    private int $bytesRead = 0;

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

    /** @param resource $stream */
    public function verify($stream): BackupManifest
    {
        return $this->read($stream);
    }

    /**
     * @param  resource  $stream
     * @param  (callable(string, string): void)|null  $onChunk
     */
    public function read($stream, ?callable $onChunk = null): BackupManifest
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw $this->invalidBundle();
        }

        $this->bytesRead = 0;
        $magic = $this->readExact($stream, strlen(BackupBundleFormat::MAGIC));
        if (! hash_equals(BackupBundleFormat::MAGIC, $magic)) {
            throw $this->invalidBundle();
        }

        $manifestLengthBytes = $this->readExact($stream, 4);
        $unpacked = unpack('Nlength', $manifestLengthBytes);
        if (! is_array($unpacked)) {
            throw $this->invalidBundle();
        }
        $manifestLength = (int) $unpacked['length'];
        if ($manifestLength < 1 || $manifestLength > $this->limits['max_manifest_bytes']) {
            throw $this->invalidBundle();
        }

        try {
            $decoded = json_decode($this->readExact($stream, $manifestLength), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupException('restore_invalid_bundle', 'فایل پشتیبان معتبر نیست.', $exception);
        }

        if (! is_array($decoded)) {
            throw $this->invalidBundle();
        }

        $manifest = BackupManifest::fromArray($decoded);
        $manifestData = $manifest->toArray();
        $entries = $manifestData['entries'];
        if (! is_array($entries) || count($entries) > $this->limits['max_entries']) {
            throw $this->invalidBundle();
        }

        $contentHash = hash_init('sha256');
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw $this->invalidBundle();
            }

            $expectedPath = $entry['path'];
            $expectedSize = $entry['size'];
            $expectedSha = $entry['sha256'];
            if (! is_string($expectedPath) || ! is_int($expectedSize) || ! is_string($expectedSha)) {
                throw $this->invalidBundle();
            }
            BackupBundleFormat::assertRestorePath($expectedPath);

            $pathLengthBytes = $this->readExact($stream, 4);
            $pathUnpacked = unpack('Nlength', $pathLengthBytes);
            if (! is_array($pathUnpacked)) {
                throw $this->invalidBundle();
            }
            $pathLength = (int) $pathUnpacked['length'];
            if ($pathLength < 1 || $pathLength > $this->limits['max_path_bytes']) {
                throw $this->pathRejected();
            }

            $path = $this->readExact($stream, $pathLength);
            BackupBundleFormat::assertRestorePath($path);
            if (! hash_equals($expectedPath, $path)) {
                throw $this->invalidBundle();
            }

            $size = BackupBundleFormat::unpackUint64(
                $this->readExact($stream, 8),
                $this->limits['max_entry_bytes'],
            );
            if ($size !== $expectedSize) {
                throw $this->checksumMismatch();
            }

            $metadataJson = BackupBundleFormat::canonicalMetadataJson($entry);
            hash_update($contentHash, pack('N', strlen($metadataJson)).$metadataJson);
            $entryHash = hash_init('sha256');
            $remaining = $size;

            while ($remaining > 0) {
                $length = min($remaining, $this->limits['chunk_bytes']);
                $chunk = $this->readExact($stream, $length);
                hash_update($entryHash, $chunk);
                hash_update($contentHash, $chunk);
                if ($onChunk !== null) {
                    $onChunk($path, $chunk);
                }
                $remaining -= strlen($chunk);
            }

            if (! hash_equals($expectedSha, hash_final($entryHash))) {
                throw $this->checksumMismatch();
            }
        }

        $trailing = fread($stream, 1);
        if ($trailing === false) {
            throw $this->invalidBundle();
        }
        if ($trailing !== '') {
            throw $this->invalidBundle();
        }

        $contentDigest = $manifestData['content_digest_sha256'];
        if (! is_string($contentDigest) || ! hash_equals($contentDigest, hash_final($contentHash))) {
            throw $this->checksumMismatch();
        }

        return $manifest;
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        if ($length < 0 || $this->bytesRead + $length > $this->limits['max_bundle_bytes']) {
            throw $this->invalidBundle();
        }

        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, min($length - strlen($bytes), $this->limits['chunk_bytes']));
            if ($chunk === false || $chunk === '') {
                throw $this->invalidBundle();
            }
            $bytes .= $chunk;
        }

        $this->bytesRead += $length;

        return $bytes;
    }

    private function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }

    private function checksumMismatch(): BackupException
    {
        return new BackupException(
            'restore_checksum_mismatch',
            'اعتبارسنجی یکپارچگی فایل پشتیبان ناموفق بود.',
        );
    }

    private function pathRejected(): BackupException
    {
        return new BackupException(
            'restore_path_rejected',
            'مسیر موجود در فایل پشتیبان مجاز نیست.',
        );
    }
}
