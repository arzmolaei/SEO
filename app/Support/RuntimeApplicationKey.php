<?php

namespace App\Support;

use RuntimeException;

class RuntimeApplicationKey
{
    public function ensureConfigured(): void
    {
        if (trim((string) config('app.key', '')) !== '') {
            return;
        }

        $path = storage_path('app/installer/runtime-app-key');
        $key = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if ($key === '') {
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create installer key directory.');
            }

            $key = 'base64:'.base64_encode(random_bytes(32));

            if (file_put_contents($path, $key.PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to persist installer application key.');
            }

            @chmod($path, 0600);
        }

        config(['app.key' => $key]);
    }
}
