<?php

namespace App\Services\Update;

use App\Support\ReleasePackageContract;
use App\Support\Update\UpdatePackageReader;
use Throwable;

final class UpdateReleaseStager
{
    private readonly string $installationRoot;

    private readonly string $releaseRoot;

    private readonly string $stablePublicRoot;

    public function __construct(
        private readonly UpdatePackageReader $reader,
        private readonly ReleasePackageContract $contract,
        ?string $installationRoot = null,
        ?string $releaseRoot = null,
        ?string $stablePublicRoot = null,
    ) {
        $root = $installationRoot
            ?? (defined('SEO_OS_INSTALLATION_ROOT') ? (string) constant('SEO_OS_INSTALLATION_ROOT') : base_path());
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw $this->releaseConflict();
        }

        $this->installationRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->releaseRoot = rtrim(
            $releaseRoot ?? $this->installationRoot.'/storage/app/lifecycle/update/releases',
            DIRECTORY_SEPARATOR,
        );
        $this->stablePublicRoot = rtrim(
            $stablePublicRoot ?? $this->installationRoot.'/public',
            DIRECTORY_SEPARATOR,
        );

        $this->assertConfiguredChild($this->releaseRoot);
        $this->assertConfiguredChild($this->stablePublicRoot);
    }

    /** @return array{release_id:string,release_root:string} */
    public function stage(string $verifiedUploadPath, UpdateManifest $manifest): array
    {
        if (is_link($verifiedUploadPath) || ! is_file($verifiedUploadPath)) {
            throw $this->invalidPackage();
        }

        $packageDigest = hash_file('sha256', $verifiedUploadPath);
        if (! is_string($packageDigest) || preg_match('/\A[a-f0-9]{64}\z/', $packageDigest) !== 1) {
            throw $this->invalidPackage();
        }

        $manifestData = $manifest->toArray();
        $targetVersion = $manifestData['target_product_version'] ?? null;
        $launcherSchema = $manifestData['launcher_schema'] ?? null;
        if (! is_string($targetVersion) || ! is_string($launcherSchema)) {
            throw $this->invalidPackage();
        }

        $releaseId = $this->releaseId($targetVersion, $packageDigest);
        $slot = $this->releaseRoot.DIRECTORY_SEPARATOR.$releaseId;
        $this->ensureDirectory($this->releaseRoot);

        if (file_exists($slot) || is_link($slot)) {
            $this->assertExistingSlot($slot, $releaseId, $targetVersion, $packageDigest, $launcherSchema);

            return ['release_id' => $releaseId, 'release_root' => $slot];
        }

        $temporary = $this->releaseRoot.DIRECTORY_SEPARATOR.'.'.$releaseId.'.tmp-'.bin2hex(random_bytes(6));
        if (file_exists($temporary) || is_link($temporary)) {
            throw $this->releaseConflict();
        }
        $this->ensureDirectory($temporary);

        try {
            $stream = @fopen($verifiedUploadPath, 'rb');
            if ($stream === false) {
                throw $this->invalidPackage();
            }

            try {
                $readManifest = $this->reader->read(
                    $stream,
                    function (string $path, string $chunk) use ($temporary): void {
                        $target = $this->payloadPath($temporary, $path);
                        $this->ensureDirectory(dirname($target));
                        if (is_link($target) || file_put_contents($target, $chunk, FILE_APPEND | LOCK_EX) === false) {
                            throw $this->releaseConflict();
                        }
                        @chmod($target, 0600);
                    },
                );
            } finally {
                fclose($stream);
            }

            if (! hash_equals($manifest->canonicalJson(), $readManifest->canonicalJson())) {
                throw $this->invalidPackage();
            }

            $entries = $manifestData['entries'] ?? null;
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw $this->invalidPackage();
            }
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                    throw $this->invalidPackage();
                }

                $target = $this->payloadPath($temporary, $entry['path']);
                if (! file_exists($target)) {
                    $this->ensureDirectory(dirname($target));
                    if (file_put_contents($target, '', LOCK_EX) === false) {
                        throw $this->releaseConflict();
                    }
                    @chmod($target, 0600);
                }
            }

            $this->ensureDirectory($temporary.'/bootstrap/cache');
            $this->assertContract($temporary);

            $version = trim((string) @file_get_contents($temporary.'/VERSION'));
            if (! hash_equals($targetVersion, $version)) {
                throw $this->invalidPackage();
            }

            $marker = [
                'schema' => 'seo-release-slot/1',
                'release_id' => $releaseId,
                'product_version' => $targetVersion,
                'package_digest_sha256' => $packageDigest,
                'launcher_schema' => $launcherSchema,
            ];
            $this->writeJsonFile($temporary.'/release.json', $marker);

            if (! @rename($temporary, $slot)) {
                if (file_exists($slot)) {
                    $this->assertExistingSlot($slot, $releaseId, $targetVersion, $packageDigest, $launcherSchema);
                    $this->removeTree($temporary);
                } else {
                    throw $this->releaseConflict();
                }
            }

            $this->assertExistingSlot($slot, $releaseId, $targetVersion, $packageDigest, $launcherSchema);

            return ['release_id' => $releaseId, 'release_root' => $slot];
        } catch (Throwable $exception) {
            if (file_exists($temporary)) {
                try {
                    $this->removeTree($temporary);
                } catch (Throwable) {
                    // Preserve the original staging failure.
                }
            }

            if ($exception instanceof UpdateException) {
                throw $exception;
            }

            throw new UpdateException(
                'update_release_conflict',
                'نسخه جدید در فضای امن آماده‌سازی نشد.',
                $exception,
            );
        }
    }

    public function preparePublicAssets(string $releaseRoot, UpdateManifest $manifest): void
    {
        $slotReal = realpath($releaseRoot);
        if ($slotReal === false || $slotReal !== $releaseRoot || is_link($releaseRoot) || ! is_dir($releaseRoot)) {
            throw $this->releaseConflict();
        }

        $entries = $manifest->toArray()['entries'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw $this->invalidPackage();
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw $this->invalidPackage();
            }
            $path = $entry['path'] ?? null;
            $expectedHash = $entry['sha256'] ?? null;
            if (! is_string($path) || ! is_string($expectedHash)) {
                throw $this->invalidPackage();
            }
            if (! str_starts_with($path, 'public/build/assets/')) {
                continue;
            }

            $source = $this->payloadPath($releaseRoot, $path);
            if (is_link($source) || ! is_file($source)) {
                throw $this->publicAssetConflict();
            }
            $sourceHash = hash_file('sha256', $source);
            if (! is_string($sourceHash) || ! hash_equals($expectedHash, $sourceHash)) {
                throw $this->publicAssetConflict();
            }

            $relativePublic = substr($path, strlen('public/'));
            $destination = $this->stablePublicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePublic);
            $this->assertConfiguredChild($destination);

            if (is_link($destination)) {
                throw $this->publicAssetConflict();
            }
            if (is_file($destination)) {
                $existingHash = hash_file('sha256', $destination);
                if (! is_string($existingHash) || ! hash_equals($expectedHash, $existingHash)) {
                    throw $this->publicAssetConflict();
                }

                continue;
            }
            if (file_exists($destination)) {
                throw $this->publicAssetConflict();
            }

            $this->ensureDirectory(dirname($destination));
            $temporary = $destination.'.tmp-'.bin2hex(random_bytes(6));
            if (! @copy($source, $temporary)) {
                @unlink($temporary);
                throw $this->publicAssetConflict();
            }
            @chmod($temporary, 0644);
            $copiedHash = hash_file('sha256', $temporary);
            if (! is_string($copiedHash) || ! hash_equals($expectedHash, $copiedHash) || ! @rename($temporary, $destination)) {
                @unlink($temporary);
                throw $this->publicAssetConflict();
            }
        }
    }

    private function releaseId(string $version, string $packageDigest): string
    {
        $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);
        if (! is_string($safeVersion) || $safeVersion === '') {
            throw $this->invalidPackage();
        }
        $safeVersion = substr($safeVersion, 0, 111);

        return $safeVersion.'-'.substr($packageDigest, 0, 16);
    }

    private function assertExistingSlot(
        string $slot,
        string $releaseId,
        string $version,
        string $packageDigest,
        string $launcherSchema,
    ): void {
        if (is_link($slot) || ! is_dir($slot) || realpath($slot) !== $slot) {
            throw $this->releaseConflict();
        }

        $markerPath = $slot.'/release.json';
        if (is_link($markerPath) || ! is_file($markerPath)) {
            throw $this->releaseConflict();
        }

        $raw = @file_get_contents($markerPath);
        $marker = is_string($raw) ? json_decode($raw, true) : null;
        $expected = [
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $packageDigest,
            'launcher_schema' => $launcherSchema,
        ];
        if ($marker !== $expected || trim((string) @file_get_contents($slot.'/VERSION')) !== $version) {
            throw $this->releaseConflict();
        }

        $this->assertContract($slot);
    }

    private function assertContract(string $root): void
    {
        $inspection = $this->contract->inspect($root);
        if (! $inspection['valid']) {
            throw $this->releaseConflict();
        }
    }

    /** @param array<string, string> $value */
    private function writeJsonFile(string $path, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw $this->releaseConflict();
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw $this->releaseConflict();
        }
        @chmod($path, 0600);
    }

    private function payloadPath(string $root, string $packagePath): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $packagePath);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw $this->releaseConflict();
        }
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw $this->releaseConflict();
        }
        @chmod($path, 0700);
    }

    private function assertConfiguredChild(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->installationRoot);
        if ($normalized !== $root && ! str_starts_with($normalized, $root.'/')) {
            throw $this->releaseConflict();
        }
        if (preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1) {
            throw $this->releaseConflict();
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw $this->releaseConflict();
        }
        if (is_file($path)) {
            if (! @unlink($path)) {
                throw $this->releaseConflict();
            }

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw $this->releaseConflict();
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path.DIRECTORY_SEPARATOR.$item);
        }
        if (! @rmdir($path)) {
            throw $this->releaseConflict();
        }
    }

    private function invalidPackage(): UpdateException
    {
        return new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.');
    }

    private function releaseConflict(): UpdateException
    {
        return new UpdateException('update_release_conflict', 'نسخه جدید در فضای امن آماده‌سازی نشد.');
    }

    private function publicAssetConflict(): UpdateException
    {
        return new UpdateException('update_public_asset_conflict', 'فایل‌های عمومی نسخه جدید با وضعیت موجود سازگار نیستند.');
    }
}
