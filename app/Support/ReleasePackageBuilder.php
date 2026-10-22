<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ReleasePackageBuilder
{
    /** @var list<string> */
    private const RUNTIME_FILES = [
        'artisan',
        'composer.json',
        'composer.lock',
        '.env.example',
    ];

    /** @var list<string> */
    private const RUNTIME_DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database/migrations',
        'resources/views',
        'routes',
        'vendor',
        'public/build',
        'public/fonts/open',
    ];

    /** @var list<string> */
    private const RUNTIME_PUBLIC_FILES = [
        'public/index.php',
        'public/robots.txt',
        'public/favicon.ico',
    ];

    /** @var list<string> */
    private const WRITABLE_DIRECTORIES = [
        'bootstrap/cache',
        'storage/app/private',
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ];

    public function __construct(private readonly ReleasePackageContract $contract = new ReleasePackageContract) {}

    public function build(string $source, string $destination): void
    {
        $source = $this->normalizeRoot($source);
        $destination = $this->normalizeRoot($destination);

        if (! is_dir($source)) {
            throw new RuntimeException('Release source directory does not exist.');
        }

        if ($source === $destination || str_starts_with($destination.DIRECTORY_SEPARATOR, $source.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Release destination must be outside the source tree.');
        }

        if (file_exists($destination)) {
            $this->removeTree($destination);
        }

        if (! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException('Unable to create release destination.');
        }

        foreach (self::RUNTIME_FILES as $path) {
            $this->copyRequiredFile($source, $destination, $path);
        }

        foreach (self::RUNTIME_PUBLIC_FILES as $path) {
            $this->copyOptionalFile($source, $destination, $path);
        }

        foreach (self::RUNTIME_DIRECTORIES as $path) {
            $this->copyRequiredDirectory($source, $destination, $path);
        }

        foreach (self::WRITABLE_DIRECTORIES as $path) {
            $directory = $destination.DIRECTORY_SEPARATOR.$path;

            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create runtime directory: {$path}");
            }
        }

        $report = $this->contract->inspect($destination);

        if (! $report['valid']) {
            $details = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            throw new RuntimeException("Release package failed contract validation: {$details}");
        }
    }

    private function copyRequiredFile(string $source, string $destination, string $path): void
    {
        $from = $source.DIRECTORY_SEPARATOR.$path;

        if (! is_file($from) || is_link($from)) {
            throw new RuntimeException("Required release file is missing or unsafe: {$path}");
        }

        $this->copyFile($from, $destination.DIRECTORY_SEPARATOR.$path);
    }

    private function copyOptionalFile(string $source, string $destination, string $path): void
    {
        $from = $source.DIRECTORY_SEPARATOR.$path;

        if (! file_exists($from)) {
            return;
        }

        if (! is_file($from) || is_link($from)) {
            throw new RuntimeException("Optional release file is unsafe: {$path}");
        }

        $this->copyFile($from, $destination.DIRECTORY_SEPARATOR.$path);
    }

    private function copyRequiredDirectory(string $source, string $destination, string $path): void
    {
        $from = $source.DIRECTORY_SEPARATOR.$path;

        if (! is_dir($from) || is_link($from)) {
            throw new RuntimeException("Required release directory is missing or unsafe: {$path}");
        }

        $this->copyDirectory($from, $destination.DIRECTORY_SEPARATOR.$path);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException("Unable to create directory: {$destination}");
        }

        $iterator = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException("Symlinks are not allowed in release inputs: {$item->getPathname()}");
            }

            $target = $destination.DIRECTORY_SEPARATOR.$item->getFilename();

            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif ($item->isFile()) {
                $this->copyFile($item->getPathname(), $target);
            }
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("Unable to copy release file: {$source}");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException("Unable to remove release destination: {$path}");
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $removed = $item->isDir() && ! $item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());

            if (! $removed) {
                throw new RuntimeException("Unable to clean release destination: {$item->getPathname()}");
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException("Unable to remove release destination: {$path}");
        }
    }

    private function normalizeRoot(string $path): string
    {
        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
