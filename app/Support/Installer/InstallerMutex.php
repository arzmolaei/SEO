<?php

namespace App\Support\Installer;

use RuntimeException;

class InstallerMutex
{
    private int $depth = 0;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config('installer.mutex_path');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;

            try {
                return $callback();
            } finally {
                $this->depth--;
            }
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create installer mutex directory.');
        }

        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open installer mutex file.');
        }

        @chmod($this->path, 0600);

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire installer mutex.');
            }

            $this->depth = 1;

            try {
                return $callback();
            } finally {
                $this->depth = 0;
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
