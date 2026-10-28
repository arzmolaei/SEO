<?php

final class SeoOsUpdateMaintenanceGate
{
    private const PHASES = [
        'preparing',
        'quiescing',
        'snapshot_ready',
        'staged',
        'switch_pending',
        'target_active',
        'migrating',
        'validating',
        'rollback_required',
        'migration_rollback',
        'source_switch_pending',
        'snapshot_restore_required',
        'recovery_required',
    ];

    private const INVALID_PHASE = '__invalid__';

    public function __construct(private readonly string $installationRoot) {}

    /** @return list<string> */
    public static function maintenancePhases(): array
    {
        return self::PHASES;
    }

    public function shouldBlockHttp(string $path): bool
    {
        $phase = $this->phase();
        if ($phase === null) {
            return false;
        }
        if ($phase === self::INVALID_PHASE) {
            return true;
        }
        if (! in_array($phase, self::PHASES, true)) {
            return false;
        }

        return ! self::isRecoveryHttpPath($path);
    }

    public static function isRecoveryHttpPath(string $path): bool
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        foreach ([
            '/settings/update',
            '/login',
            '/logout',
            '/confirm-password',
            '/user/confirm-password',
            '/two-factor-challenge',
        ] as $allowed) {
            if ($path === $allowed || ($allowed === '/settings/update' && str_starts_with($path, '/settings/update/'))) {
                return true;
            }
        }

        return false;
    }

    public function shouldBlockCli(): bool
    {
        $phase = $this->phase();

        return $phase === self::INVALID_PHASE || ($phase !== null && in_array($phase, self::PHASES, true));
    }

    private function phase(): ?string
    {
        $path = rtrim($this->installationRoot, DIRECTORY_SEPARATOR).'/storage/app/lifecycle/update/state.json';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            return self::INVALID_PHASE;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 65536) {
            return self::INVALID_PHASE;
        }

        $state = json_decode($raw, true);
        if (! is_array($state) || ! isset($state['phase']) || ! is_string($state['phase'])) {
            return self::INVALID_PHASE;
        }

        if (! in_array($state['phase'], [...self::PHASES, 'uploaded', 'verified', 'complete'], true)) {
            return self::INVALID_PHASE;
        }

        return $state['phase'];
    }
}

final class SeoOsRuntimeReleaseLocator
{
    public const LAUNCHER_SCHEMA = 'seo-launcher/2';

    private const POINTER_SCHEMA = 'seo-release-pointer/1';

    private const SLOT_SCHEMA = 'seo-release-slot/1';

    private const POINTER_RELATIVE_PATH = 'storage/app/lifecycle/update/active-release.json';

    private const RELEASES_RELATIVE_PATH = 'storage/app/lifecycle/update/releases';

    private const MAX_METADATA_BYTES = 16 * 1024;

    private const MAX_VERSION_BYTES = 256;

    private readonly string $installationRoot;

    public function __construct(string $installationRoot)
    {
        $resolved = realpath($installationRoot);
        if ($resolved === false || ! is_dir($resolved)) {
            throw $this->invalidState();
        }

        $this->installationRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{
     *     kind: 'root'|'slot',
     *     release_root: string,
     *     release_id: ?string,
     *     product_version: string,
     *     package_digest_sha256: ?string
     * }
     */
    public function resolve(): array
    {
        $pointerPath = $this->path(self::POINTER_RELATIVE_PATH);
        if (! file_exists($pointerPath) && ! is_link($pointerPath)) {
            $installedPath = $this->path('storage/app/.installed');
            if (file_exists($installedPath) || is_link($installedPath)) {
                $this->assertCanonicalPrivateFile($installedPath);
                $installed = $this->readMetadata($installedPath);
                if (($installed['product_version'] ?? null) !== $this->readVersion($this->path('VERSION'))) {
                    throw $this->invalidState();
                }
            }
            foreach (['state.json', 'last-complete.json', 'releases'] as $evidence) {
                $path = $this->path('storage/app/lifecycle/update/'.$evidence);
                if (file_exists($path) || is_link($path)) {
                    throw $this->invalidState();
                }
            }

            return [
                'kind' => 'root',
                'release_root' => $this->installationRoot,
                'release_id' => null,
                'product_version' => $this->readVersion($this->installationRoot.DIRECTORY_SEPARATOR.'VERSION'),
                'package_digest_sha256' => null,
            ];
        }

        $this->assertCanonicalPrivateFile($pointerPath);
        $pointer = $this->readMetadata($pointerPath);
        if (($pointer['schema'] ?? null) === 'seo-root-release-pointer/1') {
            $this->assertExactKeys($pointer, ['schema', 'product_version', 'launcher_schema']);
            $version = $this->validatedVersion($pointer['product_version'] ?? null);
            if (($pointer['launcher_schema'] ?? null) !== self::LAUNCHER_SCHEMA
                || $this->readVersion($this->path('VERSION')) !== $version) {
                throw $this->invalidState();
            }

            return ['kind' => 'root', 'release_root' => $this->installationRoot,
                'release_id' => null, 'product_version' => $version, 'package_digest_sha256' => null];
        }
        $this->assertExactKeys($pointer, ['schema', 'release_id', 'product_version', 'package_digest_sha256', 'launcher_schema']);
        if (($pointer['schema'] ?? null) !== self::POINTER_SCHEMA) {
            throw $this->invalidState();
        }

        $releaseId = $this->validatedReleaseId($pointer['release_id'] ?? null);
        $version = $this->validatedVersion($pointer['product_version'] ?? null);
        $digest = $this->validatedDigest($pointer['package_digest_sha256'] ?? null);
        if (($pointer['launcher_schema'] ?? null) !== self::LAUNCHER_SCHEMA) {
            throw $this->invalidState();
        }

        $releasesRoot = $this->path(self::RELEASES_RELATIVE_PATH);
        $realReleasesRoot = $this->canonicalDirectory($releasesRoot);
        $slotPath = $releasesRoot.DIRECTORY_SEPARATOR.$releaseId;
        $realSlot = $this->canonicalDirectory($slotPath);
        if (dirname($realSlot) !== $realReleasesRoot) {
            throw $this->invalidState();
        }
        if (! hash_equals($version, $this->readVersion($slotPath.DIRECTORY_SEPARATOR.'VERSION'))) {
            throw $this->invalidState();
        }

        $markerPath = $slotPath.DIRECTORY_SEPARATOR.'release.json';
        $this->assertCanonicalPrivateFile($markerPath, false);
        $marker = $this->readMetadata($markerPath);
        $this->assertExactKeys($marker, ['schema', 'release_id', 'product_version', 'package_digest_sha256', 'launcher_schema']);
        if (($marker['schema'] ?? null) !== self::SLOT_SCHEMA
            || ($marker['release_id'] ?? null) !== $releaseId
            || ($marker['product_version'] ?? null) !== $version
            || ($marker['package_digest_sha256'] ?? null) !== $digest
            || ($marker['launcher_schema'] ?? null) !== self::LAUNCHER_SCHEMA) {
            throw $this->invalidState();
        }

        return [
            'kind' => 'slot',
            'release_root' => $slotPath,
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
        ];
    }

    /**
     * This path runs without Composer, under the same OS lock as application lifecycle work.
     *
     * @param  array<string, mixed>  $failedRelease
     */
    public function recoverPreMigrationBootstrapFailure(array $failedRelease): bool
    {
        $lock = null;
        try {
            $journalPath = $this->path('storage/app/lifecycle/update/state.json');
            $this->assertCanonicalPrivateFile($journalPath);
            $state = $this->readMetadata($journalPath, 65536);
            $lockPath = $this->path('storage/app/lifecycle/lifecycle.lock');
            if (($state['lifecycle_mutex_path_sha256'] ?? null) !== hash('sha256', $lockPath)) {
                return false;
            }
            $this->assertCanonicalPrivateFile($lockPath);
            $lock = @fopen($lockPath, 'r+');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $state = $this->readMetadata($journalPath, 65536);
            $phase = $state['phase'] ?? null;
            if (
                ($state['lifecycle_mutex_path_sha256'] ?? null) !== hash('sha256', $lockPath)
                || ! in_array($phase, ['switch_pending', 'target_active', 'migrating', 'validating'], true)
                || ($failedRelease['kind'] ?? null) !== 'slot'
                || $this->resolve() !== $failedRelease
            ) {
                return false;
            }

            $targetMatches = ($state['target_release_id'] ?? null) === $failedRelease['release_id']
                && ($state['target_product_version'] ?? null) === $failedRelease['product_version']
                && ($state['package_digest_sha256'] ?? null) === $failedRelease['package_digest_sha256'];
            $beforeMigration = ($phase === 'target_active' && ($state['migration_started'] ?? null) === false)
                || ($phase === 'switch_pending' && (! array_key_exists('migration_started', $state) || $state['migration_started'] === false));
            $reverted = false;
            if ($targetMatches && $beforeMigration) {
                try {
                    $this->restoreRecordedSourcePointer($state);
                    $reverted = true;
                } catch (Throwable) {
                    // A missing/unprovable source is retained for manual recovery, never guessed.
                }
            }
            $this->writeRecoveryMetadata($journalPath, [
                ...$state,
                'phase' => 'recovery_required',
                'recovery_code' => 'update_target_bootstrap_failed',
                'recovery_reverted_to_source' => $reverted,
            ]);

            return $reverted;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function restoreRecordedSourcePointer(array $state): void
    {
        $version = $this->validatedVersion($state['source_product_version'] ?? null);
        $pointerPath = $this->path(self::POINTER_RELATIVE_PATH);
        if (($state['source_release_kind'] ?? null) === 'root') {
            if (
                ($state['source_release_id'] ?? null) !== null
                || $this->readVersion($this->path('VERSION')) !== $version
            ) {
                throw $this->invalidState();
            }

            $this->writeRecoveryMetadata($pointerPath, [
                'schema' => 'seo-root-release-pointer/1',
                'product_version' => $version,
                'launcher_schema' => self::LAUNCHER_SCHEMA,
            ]);

            return;
        }
        if (($state['source_release_kind'] ?? null) !== 'slot') {
            throw $this->invalidState();
        }
        $releaseId = $this->validatedReleaseId($state['source_release_id'] ?? null);
        $releasesRoot = $this->canonicalDirectory($this->path(self::RELEASES_RELATIVE_PATH));
        $slot = $this->canonicalDirectory($releasesRoot.'/'.$releaseId);
        if (dirname($slot) !== $releasesRoot) {
            throw $this->invalidState();
        }
        $markerPath = $slot.'/release.json';
        $this->assertCanonicalPrivateFile($markerPath);
        $marker = $this->readMetadata($markerPath);
        $this->assertExactKeys($marker, ['schema', 'release_id', 'product_version', 'package_digest_sha256', 'launcher_schema']);
        if (
            ($marker['schema'] ?? null) !== self::SLOT_SCHEMA
            || ($marker['release_id'] ?? null) !== $releaseId
            || ($marker['product_version'] ?? null) !== $version
            || ($marker['launcher_schema'] ?? null) !== self::LAUNCHER_SCHEMA
            || $this->readVersion($slot.'/VERSION') !== $version
        ) {
            throw $this->invalidState();
        }
        $digest = $this->validatedDigest($marker['package_digest_sha256'] ?? null);
        $this->writeRecoveryMetadata($pointerPath, [
            'schema' => self::POINTER_SCHEMA,
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => self::LAUNCHER_SCHEMA,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function writeRecoveryMetadata(string $path, array $metadata): void
    {
        $this->canonicalDirectory(dirname($path));
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65536) {
            throw $this->invalidState();
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw $this->invalidState();
        }
        try {
            if (! chmod($temporary, 0600) || fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                throw $this->invalidState();
            }
            fclose($stream);
            if (! rename($temporary, $path)) {
                throw $this->invalidState();
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
        }
    }

    private function path(string $relative): string
    {
        return $this->installationRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function canonicalDirectory(string $path): string
    {
        if (is_link($path) || ! is_dir($path)) {
            throw $this->invalidState();
        }

        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path || ! str_starts_with($resolved.DIRECTORY_SEPARATOR, $this->installationRoot.DIRECTORY_SEPARATOR)) {
            throw $this->invalidState();
        }

        return $resolved;
    }

    private function assertCanonicalPrivateFile(string $path, bool $requirePrivateMode = true): void
    {
        if (is_link($path) || ! is_file($path)) {
            throw $this->invalidState();
        }

        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path || ! str_starts_with($resolved, $this->installationRoot.DIRECTORY_SEPARATOR)) {
            throw $this->invalidState();
        }

        if ($requirePrivateMode && PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $path);
            $permissions = fileperms($path);
            if ($permissions === false || ($permissions & 0022) !== 0) {
                throw $this->invalidState();
            }
        }
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, int $maximumBytes = self::MAX_METADATA_BYTES): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (! is_int($size) || $size < 2 || $size > $maximumBytes) {
            throw $this->invalidState();
        }

        $content = file_get_contents($path);
        if (! is_string($content) || strlen($content) !== $size) {
            throw $this->invalidState();
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidState();
        }

        if (! is_array($decoded)) {
            throw $this->invalidState();
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $metadata, array $expected): void
    {
        $actual = array_keys($metadata);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->invalidState();
        }
    }

    private function readVersion(string $path): string
    {
        if (is_link($path) || ! is_file($path)) {
            throw $this->invalidState();
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_VERSION_BYTES) {
            throw $this->invalidState();
        }

        $content = file_get_contents($path);
        if (! is_string($content)) {
            throw $this->invalidState();
        }

        return $this->validatedVersion(trim($content));
    }

    private function validatedReleaseId(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $value) !== 1) {
            throw $this->invalidState();
        }

        return $value;
    }

    private function validatedVersion(mixed $value): string
    {
        if (! is_string($value) || $value === 'dev' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,127}\z/', $value) !== 1) {
            throw $this->invalidState();
        }

        return $value;
    }

    private function validatedDigest(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw $this->invalidState();
        }

        return $value;
    }

    private function invalidState(): RuntimeException
    {
        return new RuntimeException('The active application release is unavailable.');
    }
}
