<?php

namespace App\Services\Update;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\RestoreJournal;
use App\Support\InstallationState;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Lifecycle\ProductVersion;
use App\Support\ReleasePackageContract;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageFormat;
use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ValidateSystemUpdateHealth
{
    public function __construct(
        private readonly ProductVersion $productVersion,
        private readonly ActiveReleasePointer $pointer,
        private readonly ApplicationSchemaFingerprint $schemaFingerprint,
        private readonly UpdateRuntimeIdentity $runtimeIdentity,
        private readonly InstallationState $installationState,
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly ReleasePackageContract $releaseContract,
    ) {}

    /** @param array<string, mixed> $expected */
    public function handle(array $expected): UpdateHealthReport
    {
        $failures = [];
        /** @param Closure(): bool $check */
        $verify = static function (string $code, Closure $check) use (&$failures): void {
            try {
                if (! $check()) {
                    $failures[] = $code;
                }
            } catch (Throwable) {
                $failures[] = $code;
            }
        };
        $verify('target_release', fn (): bool => $this->targetMatches($expected));
        $verify('database', fn (): bool => DB::connection()->selectOne('SELECT 1') !== null);
        $verify('schema', fn (): bool => $this->schemaFingerprint->forConnection(DB::connection()) === ($expected['target_schema_fingerprint'] ?? null));
        $verify('runtime_identity', fn (): bool => $this->identityMatches($expected));
        $verify('owner', fn (): bool => $this->ownerReady());
        $verify('encryption', fn (): bool => $this->encryptionReady());
        $verify('stable_storage', fn (): bool => $this->storageReady());
        $verify('assets', fn (): bool => $this->assetsReady());
        $verify('lifecycle', fn (): bool => $this->lifecycleMatches($expected));

        return new UpdateHealthReport($failures);
    }

    /** @param array<string, mixed> $expected */
    private function targetMatches(array $expected): bool
    {
        $current = $this->pointer->current();

        return ($current['kind'] ?? null) === 'slot'
            && ($current['release_id'] ?? null) === ($expected['target_release_id'] ?? null)
            && ($current['product_version'] ?? null) === ($expected['target_product_version'] ?? null)
            && ($current['package_digest_sha256'] ?? null) === ($expected['package_digest_sha256'] ?? null)
            && ($current['release_root'] ?? null) === realpath(base_path())
            && $this->productVersion->requireDistributable() === ($expected['target_product_version'] ?? null);
    }

    /** @param array<string, mixed> $expected */
    private function identityMatches(array $expected): bool
    {
        foreach ($this->runtimeIdentity->capture() as $key => $actual) {
            if (! is_string($expected[$key] ?? null) || ! hash_equals($expected[$key], $actual)) {
                return false;
            }
        }
        $details = $this->installationState->details();

        return $details !== null && in_array($details['product_version'] ?? null, [
            $expected['source_product_version'] ?? null,
            $expected['target_product_version'] ?? null,
        ], true);
    }

    private function ownerReady(): bool
    {
        $details = $this->installationState->details();
        $ownerId = $details['owner_user_id'] ?? null;
        if (! is_int($ownerId) || $ownerId < 1) {
            return false;
        }
        $owner = User::query()->find($ownerId);

        return $owner !== null && $owner->email_verified_at !== null && $owner->onboarded_at !== null
            && Membership::query()->where('user_id', $ownerId)->where('role', WorkspaceRole::Owner->value)->whereHas('workspace')->exists();
    }

    private function encryptionReady(): bool
    {
        $key = config('app.key');
        $environment = (new InstallerEnvironment(app()->environmentFilePath()))->values();
        $persistent = $environment['APP_KEY'] ?? null;
        if (! is_string($key) || ! is_string($persistent) || $key === '' || ! hash_equals($key, $persistent)) {
            return false;
        }
        $rawKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $encrypter = app('encrypter');
        if (! is_string($rawKey) || ! $encrypter instanceof Encrypter || ! hash_equals($rawKey, $encrypter->getKey())) {
            return false;
        }
        $probe = bin2hex(random_bytes(32));

        return hash_equals($probe, $encrypter->decryptString($encrypter->encryptString($probe)));
    }

    private function storageReady(): bool
    {
        $root = $this->pointer->installationRoot();
        if (realpath(storage_path()) !== $root.'/storage' || realpath(app()->environmentPath()) !== $root) {
            return false;
        }
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            if (! $this->probeWritable($root.'/storage/'.$path)) {
                return false;
            }
        }

        return $this->probeWritable(base_path('bootstrap/cache'));
    }

    private function probeWritable(string $directory): bool
    {
        if (! is_dir($directory) || is_link($directory) || ! is_writable($directory)) {
            return false;
        }
        $path = $directory.'/.seo-update-health-'.bin2hex(random_bytes(8));
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            return false;
        }
        @chmod($path, 0600);
        try {
            return fwrite($stream, 'probe') === 5 && fflush($stream);
        } finally {
            fclose($stream);
            @unlink($path);
        }
    }

    private function assetsReady(): bool
    {
        $current = $this->pointer->current();
        $root = $current['release_root'] ?? null;
        if (! is_string($root) || ! $this->releaseContract->inspect($root)['valid']) {
            return false;
        }
        $path = $root.'/public/build/manifest.json';
        $size = filesize($path);
        if (is_link($path) || ! is_int($size) || $size < 2 || $size > 4 * 1024 * 1024) {
            return false;
        }
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            return false;
        }
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || $manifest === []) {
            return false;
        }
        $hasEntry = false;
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                return false;
            }
            $hasEntry = $hasEntry || ($entry['isEntry'] ?? false) === true;
            $files = [$entry['file']];
            foreach (['css', 'assets'] as $field) {
                $values = $entry[$field] ?? [];
                if (! is_array($values) || ! array_is_list($values)) {
                    return false;
                }
                foreach ($values as $value) {
                    if (! is_string($value)) {
                        return false;
                    }
                    $files[] = $value;
                }
            }
            foreach (['imports', 'dynamicImports'] as $field) {
                $references = $entry[$field] ?? [];
                if (! is_array($references)) {
                    return false;
                }
                foreach ($references as $reference) {
                    if (! is_string($reference) || ! array_key_exists($reference, $manifest)) {
                        return false;
                    }
                }
            }
            foreach ($files as $file) {
                if (! $this->assetMatches($root, $file)) {
                    return false;
                }
            }
        }

        return $hasEntry;
    }

    private function assetMatches(string $releaseRoot, string $relative): bool
    {
        UpdatePackageFormat::assertPackagePath('public/build/'.$relative);
        $slot = $releaseRoot.'/public/build/'.$relative;
        $public = $this->pointer->installationRoot().'/public/build/'.$relative;
        foreach ([$slot, $public] as $path) {
            if (! is_file($path) || is_link($path) || realpath($path) !== $path) {
                return false;
            }
        }
        $expected = hash_file('sha256', $slot);
        $actual = hash_file('sha256', $public);

        return is_string($expected) && is_string($actual) && hash_equals($expected, $actual);
    }

    /** @param array<string, mixed> $expected */
    private function lifecycleMatches(array $expected): bool
    {
        if ($this->restoreJournal->state() !== null) {
            return false;
        }
        $state = $this->journal->state();
        if ($state === null || ! in_array($state['phase'], ['validating', 'complete'], true)) {
            return false;
        }
        foreach (['upload_id', 'target_release_id', 'target_product_version', 'package_digest_sha256'] as $key) {
            if (($state[$key] ?? null) !== ($expected[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
