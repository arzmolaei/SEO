<?php

namespace App\Services\Update;

use App\Support\Lifecycle\ProductVersion;
use App\Support\ReleasePackageContract;
use App\Support\Update\UpdatePackageFormat;
use App\Support\Update\UpdatePackageReader;
use App\Support\Update\UpdatePackageWriter;
use Throwable;

final class BuildSystemUpdatePackage
{
    public function __construct(
        private readonly UpdatePackageWriter $writer,
        private readonly UpdatePackageReader $reader,
        private readonly ReleasePackageContract $contract,
    ) {}

    /** @param array<string, mixed> $compatibility */
    public function build(string $releaseRoot, string $outputPath, array $compatibility): UpdateManifest
    {
        $root = realpath($releaseRoot);
        $parent = realpath(dirname($outputPath));
        if (
            $root === false || is_link($releaseRoot) || ! is_dir($root)
            || $parent === false || ! is_dir($parent)
            || $parent === $root || str_starts_with($parent, $root.DIRECTORY_SEPARATOR)
            || ! $this->contract->inspect($root)['valid']
        ) {
            throw $this->invalidBuild();
        }
        $outputPath = $parent.DIRECTORY_SEPARATOR.basename($outputPath);
        $temporary = $outputPath.'.tmp';
        if (file_exists($outputPath) || is_link($outputPath) || file_exists($temporary) || is_link($temporary)) {
            throw $this->invalidBuild();
        }

        $version = (new ProductVersion($root.'/VERSION'))->requireDistributable();
        $source = $compatibility['source_product_version'] ?? null;
        if (! is_string($source) || ! version_compare($version, $source, '>')) {
            throw $this->invalidBuild();
        }

        $paths = [];
        foreach (UpdatePackageFormat::runtimeFiles() as $path) {
            if (file_exists($root.'/'.$path) || is_link($root.'/'.$path)) {
                $this->collect($root, $path, $paths);
            }
        }
        foreach (UpdatePackageFormat::runtimeDirectories() as $path) {
            if (file_exists($root.'/'.$path) || is_link($root.'/'.$path)) {
                $this->collect($root, $path, $paths);
            }
        }
        ksort($paths, SORT_STRING);
        $files = [];
        $migrations = [];
        foreach ($paths as $path => $absolute) {
            $files[] = ['path' => $path, 'source_path' => $absolute, 'media_type' => 'application/octet-stream'];
            if (str_starts_with($path, 'database/migrations/')) {
                $hash = hash_file('sha256', $absolute);
                $size = filesize($absolute);
                if ($hash === false || $size === false) {
                    throw $this->invalidBuild();
                }
                $migrations[] = ['path' => $path, 'size' => $size, 'sha256' => $hash, 'media_type' => 'application/octet-stream'];
            }
        }
        $metadata = $compatibility;
        unset($metadata['source_product_version']);
        $metadata = [
            ...$metadata,
            'schema' => UpdateManifest::SCHEMA,
            'launcher_schema' => UpdateManifest::LAUNCHER_SCHEMA,
            'target_product_version' => $version,
            'allowed_source_product_versions' => [$source],
            'migration_tree_sha256' => hash('sha256', UpdatePackageFormat::canonicalMetadataJson($migrations)),
        ];

        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            throw $this->invalidBuild();
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw $this->invalidBuild();
            }
            $manifest = $this->writer->writeFiles($stream, $metadata, $files);
            $verified = $this->reader->read($stream);
            $actualMigrations = array_values(array_filter(
                $verified->toArray()['entries'],
                static fn (array $entry): bool => str_starts_with($entry['path'], 'database/migrations/'),
            ));
            if (hash('sha256', UpdatePackageFormat::canonicalMetadataJson($actualMigrations)) !== $metadata['migration_tree_sha256']) {
                throw $this->invalidBuild();
            }
            if (! hash_equals($manifest->canonicalJson(), $verified->canonicalJson()) || ! fflush($stream)) {
                throw $this->invalidBuild();
            }
            fclose($stream);
            if (file_exists($outputPath) || is_link($outputPath) || ! rename($temporary, $outputPath)) {
                throw $this->invalidBuild();
            }

            return $verified;
        } catch (Throwable $exception) {
            throw $exception instanceof UpdateException ? $exception : $this->invalidBuild($exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
        }
    }

    /** @param array<string, string> $paths */
    private function collect(string $root, string $relative, array &$paths): void
    {
        $absolute = $root.'/'.$relative;
        if (is_link($absolute)) {
            throw $this->invalidBuild();
        }
        if (is_dir($absolute)) {
            $children = scandir($absolute);
            if ($children === false) {
                throw $this->invalidBuild();
            }
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $this->collect($root, $relative.'/'.$child, $paths);
                }
            }

            return;
        }
        if (! is_file($absolute)) {
            throw $this->invalidBuild();
        }
        UpdatePackageFormat::assertPackagePath($relative);
        $paths[$relative] = $absolute;
        if (count($paths) > (int) config('update.package.max_entries', 100_000)) {
            throw $this->invalidBuild();
        }
    }

    private function invalidBuild(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_invalid_package', 'ساخت بسته به‌روزرسانی معتبر و ایمن انجام نشد.', $previous);
    }
}
