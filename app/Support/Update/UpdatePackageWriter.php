<?php

namespace App\Support\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateManifest;

final class UpdatePackageWriter
{
    /** @var array{max_manifest_bytes:int,max_path_bytes:int,max_entries:int,max_entry_bytes:int,max_package_bytes:int,chunk_bytes:positive-int} */
    private array $limits;

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
     * @param  resource  $output
     * @param  array<string, mixed>  $manifestFields
     * @param  list<array{path:string,stream:resource,media_type:string}>  $entries
     */
    public function write($output, array $manifestFields, array $entries): UpdateManifest
    {
        $this->assertStream($output);

        if ($entries === [] || count($entries) > $this->limits['max_entries']) {
            throw $this->invalidPackage();
        }

        if (array_key_exists('entries', $manifestFields) || array_key_exists('content_digest_sha256', $manifestFields)) {
            throw $this->invalidPackage();
        }

        $metadata = [];
        $seen = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $mediaType = $entry['media_type'];
            $stream = $entry['stream'];

            if (trim($mediaType) === '') {
                throw $this->invalidPackage();
            }

            UpdatePackageFormat::assertPackagePath($path);
            if (strlen($path) > $this->limits['max_path_bytes'] || isset($seen[$path])) {
                throw $this->invalidPackage();
            }
            $seen[$path] = true;
            $this->assertStream($stream);
            $this->rewindStream($stream);

            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, $this->limits['chunk_bytes']);
                if ($chunk === false) {
                    throw $this->invalidPackage();
                }
                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                if ($size > $this->limits['max_entry_bytes']) {
                    throw $this->invalidPackage();
                }
                hash_update($hash, $chunk);
            }

            $metadata[] = [
                'path' => $path,
                'size' => $size,
                'sha256' => hash_final($hash),
                'media_type' => $mediaType,
            ];
        }

        $paths = array_column($metadata, 'path');
        $sortedPaths = $paths;
        sort($sortedPaths, SORT_STRING);
        if ($paths !== $sortedPaths) {
            throw $this->invalidPackage();
        }

        $contentHash = hash_init('sha256');
        foreach ($metadata as $index => $item) {
            $metadataJson = UpdatePackageFormat::canonicalMetadataJson($item);
            hash_update($contentHash, pack('N', strlen($metadataJson)).$metadataJson);

            $stream = $entries[$index]['stream'];
            $this->rewindStream($stream);
            $this->hashStream($stream, $contentHash);
        }

        $manifest = UpdateManifest::fromArray([
            ...$manifestFields,
            'entries' => $metadata,
            'content_digest_sha256' => hash_final($contentHash),
        ]);
        $manifestJson = $manifest->canonicalJson();

        if (strlen($manifestJson) > $this->limits['max_manifest_bytes']) {
            throw $this->invalidPackage();
        }

        $packageSize = strlen(UpdatePackageFormat::MAGIC) + 4 + strlen($manifestJson);
        foreach ($metadata as $item) {
            $packageSize += 4 + strlen($item['path']) + 8 + $item['size'];
            if ($packageSize > $this->limits['max_package_bytes']) {
                throw $this->invalidPackage();
            }
        }

        if (! rewind($output) || ! ftruncate($output, 0)) {
            throw $this->invalidPackage();
        }

        $this->writeAll($output, UpdatePackageFormat::MAGIC);
        $this->writeAll($output, pack('N', strlen($manifestJson)));
        $this->writeAll($output, $manifestJson);

        foreach ($metadata as $index => $item) {
            $this->writeAll($output, pack('N', strlen($item['path'])));
            $this->writeAll($output, $item['path']);
            $this->writeAll($output, UpdatePackageFormat::packUint64($item['size']));

            $stream = $entries[$index]['stream'];
            $this->rewindStream($stream);
            while (! feof($stream)) {
                $chunk = fread($stream, $this->limits['chunk_bytes']);
                if ($chunk === false) {
                    throw $this->invalidPackage();
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
            throw $this->invalidPackage();
        }

        $metadata = stream_get_meta_data($stream);
        if ($metadata['seekable'] !== true) {
            throw $this->invalidPackage();
        }
    }

    /** @param resource $stream */
    private function rewindStream($stream): void
    {
        if (! rewind($stream)) {
            throw $this->invalidPackage();
        }
    }

    /** @param resource $stream @param \HashContext $hash */
    private function hashStream($stream, \HashContext $hash): void
    {
        while (! feof($stream)) {
            $chunk = fread($stream, $this->limits['chunk_bytes']);
            if ($chunk === false) {
                throw $this->invalidPackage();
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
                throw $this->invalidPackage();
            }
            $offset += $written;
        }
    }

    private function invalidPackage(): UpdateException
    {
        return new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.');
    }
}
