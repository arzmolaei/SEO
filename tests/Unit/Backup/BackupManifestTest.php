<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManifest;
use Tests\TestCase;

class BackupManifestTest extends TestCase
{
    public function test_manifest_canonical_json_is_deterministic_across_associative_key_order(): void
    {
        $data = $this->validManifest();

        $first = BackupManifest::fromArray($data)->canonicalJson();
        $second = BackupManifest::fromArray(array_reverse($data, true))->canonicalJson();

        $this->assertSame($first, $second);
        $this->assertSame('seo-backup/1', BackupManifest::SCHEMA);
    }

    public function test_missing_or_unknown_top_level_field_is_rejected(): void
    {
        $missing = $this->validManifest();
        unset($missing['source_installation_id']);

        try {
            BackupManifest::fromArray($missing);
            $this->fail('Manifest missing a required field was accepted.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_invalid_bundle', $exception->codeName());
        }

        $unknown = $this->validManifest();
        $unknown['future_required'] = true;

        try {
            BackupManifest::fromArray($unknown);
            $this->fail('Manifest with unknown top-level contract was accepted.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_invalid_bundle', $exception->codeName());
        }
    }

    public function test_duplicate_logical_table_is_rejected(): void
    {
        $data = $this->validManifest();
        $data['tables'][] = $data['tables'][0];

        try {
            BackupManifest::fromArray($data);
            $this->fail('Duplicate logical table was accepted.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_invalid_bundle', $exception->codeName());
        }
    }

    public function test_compatibility_requires_exact_non_dev_product_version_and_schema_fingerprint(): void
    {
        $manifest = BackupManifest::fromArray($this->validManifest());

        $manifest->assertCompatible('0.1.0-alpha.1', str_repeat('a', 64));
        $this->addToAssertionCount(1);

        foreach ([
            ['0.1.0-alpha.2', str_repeat('a', 64)],
            ['dev', str_repeat('a', 64)],
            ['0.1.0-alpha.1', str_repeat('f', 64)],
        ] as [$version, $fingerprint]) {
            try {
                $manifest->assertCompatible($version, $fingerprint);
                $this->fail('Incompatible manifest was accepted.');
            } catch (BackupException $exception) {
                $this->assertSame('restore_incompatible_schema', $exception->codeName());
            }
        }
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        $tables = array_map(
            static fn (string $table, int $index): array => [
                'name' => $table,
                'restore_order' => $index + 1,
                'cursor' => ['id'],
                'columns' => [
                    ['name' => 'id', 'type' => 'bigint', 'family' => 'integer', 'nullable' => false],
                ],
            ],
            [
                'users', 'passkeys', 'workspaces', 'memberships', 'projects', 'import_batches',
                'staged_keyword_rows', 'keywords', 'keyword_sources', 'keyword_metrics',
                'saved_keyword_views', 'change_sets', 'activity_events', 'keyword_intent_assignments',
                'review_items', 'keyword_clusters', 'keyword_cluster_members', 'pages', 'cluster_targets',
                'crawl_runs', 'crawl_urls', 'content_briefs',
            ],
            array_keys(array_fill(0, 22, null)),
        );

        return [
            'schema' => 'seo-backup/1',
            'created_at_utc' => '2026-10-23T08:00:00Z',
            'product_version' => '0.1.0-alpha.1',
            'installer_schema_version' => 2,
            'source_installation_id' => '0123456789abcdef0123456789abcdef',
            'source_owner_user_id' => 1,
            'source_database_driver' => 'mysql',
            'source_database_server_version' => '10.11.0-MariaDB',
            'application_schema_fingerprint' => str_repeat('a', 64),
            'tables' => $tables,
            'entries' => [
                [
                    'path' => 'database/users.ndjson',
                    'size' => 12,
                    'sha256' => str_repeat('b', 64),
                    'media_type' => 'application/x-ndjson',
                    'record_count' => 1,
                ],
            ],
            'excluded_runtime_tables' => [
                'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
                'jobs', 'job_batches', 'failed_jobs',
            ],
            'content_digest_sha256' => str_repeat('c', 64),
        ];
    }
}
