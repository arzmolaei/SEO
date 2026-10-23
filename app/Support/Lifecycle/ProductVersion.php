<?php

namespace App\Support\Lifecycle;

use RuntimeException;

final class ProductVersion
{
    public function __construct(private readonly ?string $versionPath = null) {}

    public function current(): string
    {
        $path = $this->versionPath ?? base_path('VERSION');

        if (! is_file($path) || is_link($path)) {
            return 'dev';
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return 'dev';
        }

        $version = trim($contents);

        return $version === '' ? 'dev' : $version;
    }

    public function isDistributable(): bool
    {
        return $this->current() !== 'dev';
    }

    public function requireDistributable(): string
    {
        $version = $this->current();

        if ($version === 'dev') {
            throw new RuntimeException('Stable product version is required.');
        }

        return $version;
    }
}
