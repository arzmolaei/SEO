<?php

namespace App\Support;

use App\Contracts\SystemProbe;
use Illuminate\Support\Facades\DB;
use Throwable;

class NativeSystemProbe implements SystemProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function pathIsWritable(string $path): bool
    {
        $resolved = $this->resolvePath($path);

        if (file_exists($resolved)) {
            return is_writable($resolved);
        }

        $parent = dirname($resolved);

        while (! file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }

        return is_writable($parent);
    }

    public function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseDriver(): string
    {
        return (string) config('database.default', '');
    }

    public function queueConnection(): string
    {
        return (string) (config('installer.original_queue_connection') ?: config('queue.default', ''));
    }

    public function schedulerSupported(): bool
    {
        return is_file(base_path('artisan')) && is_file(base_path('routes/console.php'));
    }

    public function freeDiskBytes(string $path): ?int
    {
        $bytes = @disk_free_space($this->resolvePath($path));

        return $bytes === false ? null : (int) $bytes;
    }

    public function appKeyConfigured(): bool
    {
        $key = trim((string) config('app.key', ''));

        return $key !== '' && $key !== '${APP_KEY}';
    }

    public function productionAssetsPresent(): bool
    {
        return is_file(public_path('build/manifest.json'));
    }

    private function resolvePath(string $path): string
    {
        if ($path === '.' || $path === '') {
            return base_path();
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
