<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReleasePackageContract
{
    /** @var list<string> */
    private const REQUIRED_PATHS = [
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
            if (! file_exists($root.DIRECTORY_SEPARATOR.$path)) {
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
