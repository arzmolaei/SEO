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

        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        foreach (['/settings/update', '/login', '/logout', '/confirm-password'] as $allowed) {
            if ($path === $allowed || ($allowed === '/settings/update' && str_starts_with($path, '/settings/update/'))) {
                return false;
            }
        }

        return true;
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

        return $state['phase'];
    }
}

final class SeoOsRuntimeReleaseLocator
{
    public const LAUNCHER_SCHEMA = 'seo-launcher/1';

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

    public function resolve(): array
    {
        $pointerPath = $this->path(self::POINTER_RELATIVE_PATH);
        if (! file_exists($pointerPath) && ! is_link($pointerPath)) {
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

    private function readMetadata(string $path): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (! is_int($size) || $size < 2 || $size > self::MAX_METADATA_BYTES) {
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
