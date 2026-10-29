<?php

namespace Tests\Unit\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateManifest;
use Tests\TestCase;

class UpdateManifestTest extends TestCase
{
    public function test_explicit_root_pointer_requires_launcher_v2_packages(): void
    {
        $this->assertSame('seo-launcher/2', UpdateManifest::LAUNCHER_SCHEMA);
        $data = $this->validManifest();
        $data['launcher_schema'] = 'seo-launcher/1';
        $this->assertInvalid($data);
    }

    public function test_valid_manifest_round_trips_with_canonical_json(): void
    {
        $data = $this->validManifest();

        $manifest = UpdateManifest::fromArray($data);

        $this->assertSame($data, $manifest->toArray());
        $decoded = json_decode($manifest->canonicalJson(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($data, $decoded);
        $this->assertStringContainsString('کلیدواژه‌', $manifest->canonicalJson());
    }

    public function test_manifest_requires_exact_schema_and_launcher_schema(): void
    {
        foreach ([
            ['schema', 'seo-update/999'],
            ['launcher_schema', 'seo-launcher/999'],
        ] as [$field, $value]) {
            $data = $this->validManifest();
            $data[$field] = $value;

            try {
                UpdateManifest::fromArray($data);
                $this->fail("{$field} should be rejected.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName());
            }
        }
    }

    public function test_manifest_requires_exactly_one_stable_source_version_and_a_stable_target(): void
    {
        foreach ([
            [],
            ['0.1.0-alpha.1', '0.1.0-alpha.0'],
            ['dev'],
        ] as $sources) {
            $data = $this->validManifest();
            $data['allowed_source_product_versions'] = $sources;
            $this->assertInvalid($data);
        }

        $data = $this->validManifest();
        $data['target_product_version'] = 'dev';
        $this->assertInvalid($data);
    }

    public function test_manifest_rejects_unknown_channel_bad_hashes_duplicate_entries_and_unsorted_entries(): void
    {
        $data = $this->validManifest();
        $data['channel'] = 'nightly';
        $this->assertInvalid($data);

        foreach (['source_schema_fingerprint', 'target_schema_fingerprint', 'migration_tree_sha256', 'content_digest_sha256'] as $field) {
            $data = $this->validManifest();
            $data[$field] = 'not-a-sha';
            $this->assertInvalid($data);
        }

        $data = $this->validManifest();
        $data['entries'][] = $data['entries'][0];
        $this->assertInvalid($data);

        $data = $this->validManifest();
        $data['entries'] = array_reverse($data['entries']);
        $this->assertInvalid($data);
    }

    public function test_manifest_rejects_missing_and_unknown_top_level_fields(): void
    {
        $data = $this->validManifest();
        unset($data['channel']);
        $this->assertInvalid($data);

        $data = $this->validManifest();
        $data['surprise'] = true;
        $this->assertInvalid($data);
    }

    /** @param array<string, mixed> $data */
    private function assertInvalid(array $data): void
    {
        try {
            UpdateManifest::fromArray($data);
            $this->fail('Manifest should be rejected.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_package', $exception->codeName());
        }
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        return [
            'schema' => 'seo-update/1',
            'created_at_utc' => '2026-10-23T16:30:00Z',
            'target_product_version' => '0.1.0-alpha.2',
            'allowed_source_product_versions' => ['0.1.0-alpha.1'],
            'channel' => 'development',
            'launcher_schema' => 'seo-launcher/2',
            'minimum_php_version' => '8.3.0',
            'required_php_extensions' => ['ctype', 'json', 'pdo'],
            'source_schema_fingerprint' => str_repeat('1', 64),
            'target_schema_fingerprint' => str_repeat('2', 64),
            'migration_tree_sha256' => str_repeat('3', 64),
            'entries' => [
                [
                    'path' => 'VERSION',
                    'size' => 14,
                    'sha256' => str_repeat('4', 64),
                    'media_type' => 'text/plain; note=کلیدواژه‌',
                ],
                [
                    'path' => 'app/Release.php',
                    'size' => 17,
                    'sha256' => str_repeat('5', 64),
                    'media_type' => 'application/x-httpd-php',
                ],
            ],
            'content_digest_sha256' => str_repeat('6', 64),
        ];
    }
}
