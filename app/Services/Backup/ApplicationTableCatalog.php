<?php

namespace App\Services\Backup;

final class ApplicationTableCatalog
{
    /** @var list<string> */
    public const TABLES = [
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

    /** @var list<string> */
    private const EXCLUDED_TABLES = [
        'migrations',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /** @return list<string> */
    public static function insertOrder(): array
    {
        return self::TABLES;
    }

    /** @return list<string> */
    public static function deleteOrder(): array
    {
        return array_reverse(self::TABLES);
    }

    /** @return list<string> */
    public static function excludedTables(): array
    {
        return self::EXCLUDED_TABLES;
    }

    public static function isDurable(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    public static function entryPath(string $table): string
    {
        if (! self::isDurable($table)) {
            throw new BackupException("Unknown durable table: {$table}");
        }

        return "database/{$table}.ndjson";
    }
}
