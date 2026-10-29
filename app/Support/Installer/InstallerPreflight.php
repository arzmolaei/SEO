<?php

namespace App\Support\Installer;

use App\Contracts\SystemProbe;
use App\Support\HostUploadLimit;
use Closure;

class InstallerPreflight
{
    private const MINIMUM_PHP_VERSION = '8.3.0';

    private const MINIMUM_FREE_DISK_BYTES = 134217728;

    /** Update packages (~40 MB) and restore bundles are uploaded through PHP. */
    private const RECOMMENDED_UPLOAD_BYTES = 268435456;

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'ctype',
        'fileinfo',
        'filter',
        'hash',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
    ];

    /** @param  (Closure(): ?int)|null  $uploadLimit  bytes, or null when the host sets no limit */
    public function __construct(
        private readonly SystemProbe $probe,
        private readonly ?Closure $uploadLimit = null,
    ) {}

    /**
     * @return array{
     *   ready: bool,
     *   checks: array<string, array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}>
     * }
     */
    public function check(): array
    {
        $checks = [
            'php' => $this->phpCheck(),
            'extensions' => $this->extensionsCheck(),
            'storage' => $this->storageCheck(),
            'disk' => $this->diskCheck(),
            'app_key' => $this->appKeyCheck(),
            'assets' => $this->assetsCheck(),
            'runtime_files' => $this->runtimeFilesCheck(),
            'upload_limit' => $this->uploadLimitCheck(),
        ];

        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] === 'fail') {
                return ['ready' => false, 'checks' => $checks];
            }
        }

        return ['ready' => true, 'checks' => $checks];
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function phpCheck(): array
    {
        $version = $this->probe->phpVersion();
        $passes = version_compare($version, self::MINIMUM_PHP_VERSION, '>=');

        return $this->result(
            $passes ? 'pass' : 'fail',
            true,
            $version,
            $passes ? 'PHP runtime is supported.' : 'PHP 8.3 or newer is required.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function extensionsCheck(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            fn (string $extension): bool => ! $this->probe->extensionLoaded($extension),
        ));

        return $this->result(
            $missing === [] ? 'pass' : 'fail',
            true,
            ['required' => self::REQUIRED_EXTENSIONS, 'missing' => $missing],
            $missing === []
                ? 'Required PHP extensions are available.'
                : 'One or more required PHP extensions are missing.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function storageCheck(): array
    {
        $paths = ['storage', 'bootstrap/cache'];
        $unwritable = array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $this->probe->pathIsWritable($path),
        ));

        return $this->result(
            $unwritable === [] ? 'pass' : 'fail',
            true,
            ['paths' => $paths, 'unwritable' => $unwritable],
            $unwritable === []
                ? 'Application storage paths are writable.'
                : 'Application storage paths must be writable.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function diskCheck(): array
    {
        $freeBytes = $this->probe->freeDiskBytes('.');

        if ($freeBytes === null) {
            return $this->result('warning', false, null, 'Free disk space could not be measured.');
        }

        $passes = $freeBytes >= self::MINIMUM_FREE_DISK_BYTES;

        return $this->result(
            $passes ? 'pass' : 'fail',
            true,
            $freeBytes,
            $passes ? 'Disk space is sufficient.' : 'At least 128 MiB of free disk space is required.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function appKeyCheck(): array
    {
        $configured = $this->probe->appKeyConfigured();

        return $this->result(
            $configured ? 'pass' : 'fail',
            true,
            $configured,
            $configured ? 'Application key is configured.' : 'Application key could not be prepared.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function assetsCheck(): array
    {
        $present = $this->probe->productionAssetsPresent();

        return $this->result(
            $present ? 'pass' : 'fail',
            true,
            $present,
            $present ? 'Production frontend assets are present.' : 'Built frontend assets are required.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function runtimeFilesCheck(): array
    {
        $present = $this->probe->applicationFilesPresent();

        return $this->result(
            $present ? 'pass' : 'fail',
            true,
            $present,
            $present ? 'Required application files are present.' : 'One or more required application files are missing.',
        );
    }

    /**
     * Never blocks installation: it only matters for later update/restore
     * uploads, and the operator may raise the PHP limits at any time.
     *
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function uploadLimitCheck(): array
    {
        $limit = ($this->uploadLimit ?? HostUploadLimit::current(...))();
        $sufficient = $limit === null || $limit >= self::RECOMMENDED_UPLOAD_BYTES;

        return $this->result(
            $sufficient ? 'pass' : 'warning',
            false,
            $limit,
            $sufficient
                ? 'PHP upload limits allow update packages and backups.'
                : 'Raise PHP upload_max_filesize and post_max_size to at least 256M before uploading update packages or backups.',
        );
    }

    /**
     * @param  'pass'|'warning'|'fail'  $status
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function result(string $status, bool $required, mixed $value, string $message): array
    {
        return compact('status', 'required', 'value', 'message');
    }
}
