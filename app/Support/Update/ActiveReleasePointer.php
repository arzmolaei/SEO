<?php

namespace App\Support\Update;

use App\Services\Update\UpdateException;
use JsonException;

final class ActiveReleasePointer
{
    private readonly string $installationRoot;

    private readonly string $pointerPath;

    private readonly string $releaseRoot;

    public function __construct(?string $installationRoot = null, ?string $pointerPath = null, ?string $releaseRoot = null)
    {
        $root = $installationRoot
            ?? (defined('SEO_OS_INSTALLATION_ROOT') ? (string) constant('SEO_OS_INSTALLATION_ROOT') : base_path());
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw $this->invalidState();
        }

        $this->installationRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->pointerPath = $pointerPath ?? $this->installationRoot.'/storage/app/lifecycle/update/active-release.json';
        $this->releaseRoot = $releaseRoot ?? $this->installationRoot.'/storage/app/lifecycle/update/releases';
    }

    public function activateSlot(string $releaseId, string $version, string $packageDigest): void
    {
        $this->assertReleaseId($releaseId);
        $this->assertVersion($version);
        $this->assertDigest($packageDigest);
        $slot = $this->releaseRoot.'/'.$releaseId;
        if (is_link($slot) || ! is_dir($slot) || realpath($slot) !== $slot) {
            throw $this->invalidState();
        }

        $marker = $this->readJson($slot.'/release.json');
        $expected = [
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $packageDigest,
            'launcher_schema' => 'seo-launcher/1',
        ];
        if ($marker !== $expected || trim((string) @file_get_contents($slot.'/VERSION')) !== $version) {
            throw $this->invalidState();
        }

        $this->atomicWrite([
            'schema' => 'seo-release-pointer/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $packageDigest,
            'launcher_schema' => 'seo-launcher/1',
        ]);
    }

    public function activateRoot(string $version): void
    {
        $this->assertVersion($version);
        if (trim((string) @file_get_contents($this->installationRoot.'/VERSION')) !== $version) {
            throw $this->invalidState();
        }

        @unlink($this->pointerPath.'.tmp');
        if (file_exists($this->pointerPath) && ! unlink($this->pointerPath)) {
            throw $this->invalidState();
        }
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $launcher = dirname(__DIR__, 3).'/bootstrap/runtime-release.php';
        if (! is_file($launcher)) {
            throw $this->invalidState();
        }

        require_once $launcher;

        try {
            return (new \SeoOsRuntimeReleaseLocator($this->installationRoot))->resolve();
        } catch (\Throwable $e) {
            throw new UpdateException('update_invalid_state', 'نسخه فعال برنامه معتبر نیست.', $e);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw $this->invalidState();
        }

        $raw = file_get_contents($path);
        try {
            $value = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            throw $this->invalidState();
        }

        if (! is_array($value)) {
            throw $this->invalidState();
        }

        return $value;
    }

    /** @param array<string, string> $value */
    private function atomicWrite(array $value): void
    {
        $directory = dirname($this->pointerPath);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->invalidState();
        }

        @chmod($directory, 0700);
        $tmp = $this->pointerPath.'.tmp';
        if (file_put_contents($tmp, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw $this->invalidState();
        }

        @chmod($tmp, 0600);
        if (! rename($tmp, $this->pointerPath)) {
            @unlink($tmp);
            throw $this->invalidState();
        }

        @chmod($this->pointerPath, 0600);
    }

    private function assertReleaseId(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $value) !== 1) {
            throw $this->invalidState();
        }
    }

    private function assertVersion(string $value): void
    {
        if ($value === 'dev' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,127}\z/', $value) !== 1) {
            throw $this->invalidState();
        }
    }

    private function assertDigest(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw $this->invalidState();
        }
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت نسخه فعال معتبر نیست.');
    }
}
