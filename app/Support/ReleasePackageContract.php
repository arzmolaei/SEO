<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReleasePackageContract
{
    /** @var list<string> */
    private const REQUIRED_PATHS = [
        'VERSION',
        'artisan',
        'bootstrap/app.php',
        'vendor/autoload.php',
        'public/build/manifest.json',
        'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2',
        'resources/views/app.blade.php',
        '.env.example',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATHS = [
        '.env',
        'auth.json',
        'node_modules',
        'tests',
        'resources/js',
        'resources/css',
        '.github',
        'package.json',
        'package-lock.json',
        'vite.config.ts',
        'tsconfig.json',
        'phpunit.xml',
        'phpstan.neon',
    ];

    /**
     * @return array{valid: bool, missing: list<string>, forbidden: list<string>}
     */
    public function inspect(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $missing = [];
        $forbidden = [];

        foreach (self::REQUIRED_PATHS as $path) {
            $absolutePath = $root.DIRECTORY_SEPARATOR.$path;

            if ($path === 'VERSION') {
                if (! $this->hasStableVersion($absolutePath)) {
                    $missing[] = $path;
                }

                continue;
            }

            if (! file_exists($absolutePath)) {
                $missing[] = $path;
            }
        }

        foreach (self::FORBIDDEN_PATHS as $path) {
            if (file_exists($root.DIRECTORY_SEPARATOR.$path)) {
                $forbidden[] = $path;
            }
        }

        if ($this->containsPrivateKey($root.DIRECTORY_SEPARATOR.'storage')) {
            $forbidden[] = 'storage/**/*.key';
        }

        return [
            'valid' => $missing === [] && $forbidden === [],
            'missing' => $missing,
            'forbidden' => $forbidden,
        ];
    }

    private function hasStableVersion(string $path): bool
    {
        if (! is_file($path) || is_link($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $version = trim($contents);

        return $version !== '' && $version !== 'dev';
    }

    private function containsPrivateKey(string $storagePath): bool
    {
        if (! is_dir($storagePath)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storagePath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'key') {
                return true;
            }
        }

        return false;
    }
}
