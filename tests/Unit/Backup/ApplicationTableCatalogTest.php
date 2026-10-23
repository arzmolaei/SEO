<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\ApplicationTableCatalog;
use Tests\TestCase;

class ApplicationTableCatalogTest extends TestCase
{
    // Source-of-truth guard for the verified durable restore catalog.
    public function test_catalog_is_the_exact_verified_durable_restore_order(): void
    {
        $expected = [
            'users',
            'passkeys',
            'workspaces',
            'memberships',
            'projects',
            'import_batches',
            'staged_keyword_rows',
            'keywords',
            'keyword_sources',
            'keyword_metrics',
            'saved_keyword_views',
            'change_sets',
            'activity_events',
            'keyword_intent_assignments',
            'review_items',
            'keyword_clusters',
            'keyword_cluster_members',
            'pages',
            'cluster_targets',
        ];

        $catalog = new ApplicationTableCatalog;

        $this->assertSame($expected, $catalog->insertOrder());
        $this->assertSame(array_reverse($expected), $catalog->deleteOrder());
        $this->assertSame($expected, $catalog->tableNames());
        $this->assertContains('import_batches', $catalog->tableNames());
        $this->assertNotContains('keyword_import_batches', $catalog->tableNames());
    }

    public function test_every_durable_table_uses_an_explicit_deterministic_cursor(): void
    {
        $catalog = new ApplicationTableCatalog;

        foreach ($catalog->tableNames() as $table) {
            $this->assertSame(['id'], $catalog->cursorFor($table), $table);
        }
    }

    public function test_framework_and_volatile_tables_are_explicitly_excluded(): void
    {
        $this->assertSame([
            'migrations',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
        ], (new ApplicationTableCatalog)->excludedTables());
    }
}
