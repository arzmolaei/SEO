<?php

namespace App\Support\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateManifest;
use JsonException;

final class UpdatePackageReader
{
    /** @var array{max_manifest_bytes:int,max_path_bytes:int,max_entries:int,max_entry_bytes:int,max_package_bytes:int,chunk_bytes:positive-int} */
    private array $limits;

    private int $bytesRead = 0;

    /** @param array<string, int>|null $limits */
    public function __construct(?array $limits = null)
    {
        $defaults = (array) config('update.package', []);
        $this->limits = [
            'max_manifest_bytes' => (int) ($limits['max_manifest_bytes'] ?? $defaults['max_manifest_bytes'] ?? 4 * 1024 * 1024),
            'max_path_bytes' => (int) ($limits['max_path_bytes'] ?? $defaults['max_path_bytes'] ?? 1024),
            'max_entries' => (int) ($limits['max_entries'] ?? $defaults['max_entries'] ?? 100_000),
            'max_entry_bytes' => (int) ($limits['max_entry_bytes'] ?? $defaults['max_entry_bytes'] ?? 1024 * 1024 * 1024),
            'max_package_bytes' => (int) ($limits['max_package_bytes'] ?? $defaults['max_package_bytes'] ?? 2 * 1024 * 1024 * 1024),
            'chunk_bytes' => max(1, (int) ($limits['chunk_bytes'] ?? $defaults['chunk_bytes'] ?? 64 * 1024)),
        ];
    }

    /**
     * @param resource $stream
     * @param (callable(string, string): void)|null $onChunk
     */
    public function read($stream, ?callable $onChunk = null): UpdateManifest
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw $this->invalidPackage();
        }

        $this->bytesRead = 0;
        $magic = $this->readExact($stream, strlen(UpdatePackageFormat::MAGIC));
        if (! hash_equals(UpdatePackageFormat::MAGIC, $magic)) {
            throw $this->invalidPackage();
        }

        $manifestLengthBytes = $this->readExact($stream, 4);
        $unpacked = unpack('Nlength', $manifestLengthBytes);
        if (! is_array($unpacked)) {
            throw $this->invalidPackage();
        }
        $manifestLength = (int) $unpacked['length'];
        if ($manifestLength < 1 || $manifestLength > $this->limits['max_manifest_bytes']) {
            throw $this->invalidPackage();
        }

        try {
            $decoded = json_decode($this->readExact($stream, $manifestLength), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.', $exception);
        }

        if (! is_array($decoded)) {
            throw $this->invalidPackage();
        }

        $manifest = UpdateManifest::fromArray($decoded);
        $manifestData = $manifest->toArray();
        $entries = $manifestData['entries'];
        if (! is_array($entries) || count($entries) > $this->limits['max_entries']) {
            throw $this->invalidPackage();
        }

        $contentHash = hash_init('sha256');
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw $this->invalidPackage();
            }

            $expectedPath = $entry['path'] ?? null;
            $expectedSize = $entry['size'] ?? null;
            $expectedSha = $entry['sha256'] ?? null;
            if (! is_string($expectedPath) || ! is_int($expectedSize) || ! is_string($expectedSha)) {
                throw $this->invalidPackage();
            }

            $pathLengthBytes = $this->readExact($stream, 4);
            $pathUnpacked = unpack('Nlength', $pathLengthBytes);
            if (! is_array($pathUnpacked)) {
                throw $this->invalidPackage();
            }
            $pathLength = (int) $pathUnpacked['length'];
            if ($pathLength < 1 || $pathLength > $this->limits['max_path_bytes']) {
                throw $this->invalidPackage();
            }

            $path = $this->readExact($stream, $pathLength);
            UpdatePackageFormat::assertPackagePath($path);
            if (! hash_equals($expectedPath, $path)) {
                throw $this->invalidPackage();
            }

            $size = UpdatePackageFormat::unpackUint64(
                $this->readExact($stream, 8),
                $this->limits['max_entry_bytes'],
            );
            if ($size !== $expectedSize) {
                throw $this->invalidPackage();
            }

            $metadataJson = UpdatePackageFormat::canonicalMetadataJson($entry);
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
                throw $this->invalidPackage();
            }
        }

        $trailing = fread($stream, 1);
        if ($trailing === false || $trailing !== '') {
            throw $this->invalidPackage();
        }

        $contentDigest = $manifestData['content_digest_sha256'];
        if (! is_string($contentDigest) || ! hash_equals($contentDigest, hash_final($contentHash))) {
            throw $this->invalidPackage();
        }

        return $manifest;
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        if ($length < 0 || $this->bytesRead + $length > $this->limits['max_package_bytes']) {
            throw $this->invalidPackage();
        }

        $bytes = '';
        while (strlen($bytes) < $length) {
            $readLength = min($length - strlen($bytes), $this->limits['chunk_bytes']);
            if ($readLength < 1) {
                throw $this->invalidPackage();
            }

            $chunk = fread($stream, $readLength);
            if ($chunk === false || $chunk === '') {
                throw $this->invalidPackage();
            }
            $bytes .= $chunk;
        }

        $this->bytesRead += $length;

        return $bytes;
    }

    private function invalidPackage(): UpdateException
    {
        return new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.');
    }
}
