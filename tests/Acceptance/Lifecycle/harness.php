<?php

declare(strict_types=1);

/*
 * Build/CI acceptance helper for the end-to-end lifecycle run (run.mjs).
 * It prepares disposable infrastructure, injects controlled update faults,
 * and reads evidence. Ordinary product workflows are driven through the UI.
 *
 * Commands (all output one JSON document on stdout):
 *   db:reset <database>
 *   db:query <database> <sql>
 *   instance <runtime-release> <destination>
 *   target <runtime-release> <destination> <version> <scenario> [<previous-target>]
 *   fingerprint <release-root> <database>
 *   package <release-root> <output> <source-version> <source-fp> <target-fp>
 *   inspect <installation-root>
 */

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Update\BuildSystemUpdatePackage;
use App\Support\ReleasePackageBuilder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = static function (mixed $value): never {
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    exit(0);
};
$env = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException("Missing {$name}.");
        }

        return $default;
    }

    return $value;
};
$admin = static function () use ($env): PDO {
    return new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $env('LIFECYCLE_DB_HOST', '127.0.0.1'), $env('LIFECYCLE_DB_PORT', '3306')),
        $env('LIFECYCLE_DB_ROOT_USERNAME', 'root'),
        $env('LIFECYCLE_DB_ROOT_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
};
$connect = static function (string $database) use ($env): void {
    config(['database.connections.lifecycle' => [
        'driver' => 'mariadb',
        'host' => $env('LIFECYCLE_DB_HOST', '127.0.0.1'),
        'port' => $env('LIFECYCLE_DB_PORT', '3306'),
        'database' => $database,
        'username' => $env('LIFECYCLE_DB_USERNAME'),
        'password' => $env('LIFECYCLE_DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ]]);
    DB::purge('lifecycle');
};
$safeName = static function (string $database): string {
    if (preg_match('/\Aseo_e2e_[a-z0-9_]{1,40}\z/', $database) !== 1) {
        throw new RuntimeException('Refusing a non-disposable database name.');
    }

    return $database;
};
$parseEnv = static function (string $root): array {
    $values = [];
    foreach (preg_split('/\R/', (string) @file_get_contents($root.'/.env')) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = trim($value, '"');
    }

    return $values;
};

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

try {
    switch ($command) {
        case 'db:reset':
            $name = $safeName($args[0]);
            $pdo = $admin();
            $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
            $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $out(['database' => $name]);

        case 'db:query':
            $connect($safeName($args[0]));
            $sql = $args[1];
            if (preg_match('/\A\s*(select|show)\b/i', $sql) === 1) {
                $out(array_map(static fn ($row): array => (array) $row, DB::connection('lifecycle')->select($sql)));
            }
            $out(['affected' => DB::connection('lifecycle')->affectingStatement($sql)]);

        case 'instance':
            (new ReleasePackageBuilder)->build($args[0], $args[1]);
            $out(['root' => $args[1]]);

        case 'target':
            [$runtime, $destination, $version, $scenario] = $args;
            $previous = $args[4] ?? null;
            (new ReleasePackageBuilder)->build($runtime, $destination);
            File::put($destination.'/VERSION', $version."\n");
            if ($previous !== null) {
                foreach (File::files($previous.'/database/migrations') as $file) {
                    File::copy($file->getPathname(), $destination.'/database/migrations/'.$file->getFilename());
                }
            }
            $slug = str_replace(['.', '-'], '_', $version);
            $column = 'lifecycle_'.$slug;
            $sleep = $scenario === 'slow' ? 'sleep(20);' : '';
            preg_match('/(\d+)\z/', $version, $sequence);
            File::put($destination.'/database/migrations/2099_01_01_'.str_pad($sequence[1] ?? '0', 6, '0', STR_PAD_LEFT).'_lifecycle_'.$slug.'.php', <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public \$withinTransaction = false;

    public function up(): void
    {
        {$sleep}
        Schema::table('projects', fn (Blueprint \$table) => \$table->string('{$column}')->nullable());
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint \$table) => \$table->dropColumn('{$column}'));
    }
};
PHP);
            $asset = 'lifecycle-'.hash('sha256', $version).'.js';
            File::put($destination.'/public/build/assets/'.$asset, "/* {$version} */\n");
            if ($scenario === 'broken-boot') {
                File::put($destination.'/bootstrap/providers.php', "<?php\nthrow new RuntimeException('Deliberately broken target release.');\n");
            }
            $out(['root' => $destination, 'version' => $version, 'column' => $column, 'asset' => $asset]);

        case 'fingerprint':
            [$releaseRoot, $database] = $args;
            $name = $safeName($database);
            $pdo = $admin();
            $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
            $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $connect($name);
            $exit = Artisan::call('migrate', [
                '--database' => 'lifecycle',
                '--path' => $releaseRoot.'/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new RuntimeException(Artisan::output());
            }
            $fingerprint = $app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection('lifecycle'));
            $pdo->exec("DROP DATABASE `{$name}`");
            $out(['fingerprint' => $fingerprint]);

        case 'package':
            [$releaseRoot, $output, $sourceVersion, $sourceFingerprint, $targetFingerprint] = $args;
            $app->make(BuildSystemUpdatePackage::class)->build($releaseRoot, $output, [
                'source_product_version' => $sourceVersion,
                'source_schema_fingerprint' => $sourceFingerprint,
                'target_schema_fingerprint' => $targetFingerprint,
                'channel' => 'development',
                'minimum_php_version' => '8.4.1',
                'required_php_extensions' => ['ctype', 'json', 'mbstring', 'pdo', 'pdo_mysql'],
                'created_at_utc' => '2026-10-28T00:00:00Z',
            ]);
            $out(['package' => $output, 'sha256' => hash_file('sha256', $output)]);

        case 'inspect':
            $root = $args[0];
            $environment = $parseEnv($root);
            $lock = is_file($root.'/storage/app/.installed') ? json_decode((string) file_get_contents($root.'/storage/app/.installed'), true) : null;
            $pointerPath = $root.'/storage/app/lifecycle/update/active-release.json';
            $journalPath = $root.'/storage/app/lifecycle/update/state.json';
            $journal = is_file($journalPath) ? json_decode((string) file_get_contents($journalPath), true) : null;
            $result = [
                'env_sha256' => is_file($root.'/.env') ? hash_file('sha256', $root.'/.env') : null,
                'app_key_sha256' => isset($environment['APP_KEY']) ? hash('sha256', $environment['APP_KEY']) : null,
                'database' => $environment['DB_DATABASE'] ?? null,
                'installed' => $lock === null ? null : [
                    'product_version' => $lock['product_version'] ?? null,
                    'installation_id' => $lock['installation_id'] ?? null,
                    'restored_from_installation_id' => $lock['restored_from_installation_id'] ?? null,
                ],
                'pointer' => is_file($pointerPath) ? json_decode((string) file_get_contents($pointerPath), true) : null,
                'journal_phase' => is_array($journal) ? ($journal['phase'] ?? null) : null,
                'journal_recovery_code' => is_array($journal) ? ($journal['recovery_code'] ?? null) : null,
                'safety_snapshots' => count(glob($root.'/storage/app/lifecycle/update/safety/*.seobak') ?: []),
                'release_slots' => array_map('basename', glob($root.'/storage/app/lifecycle/update/releases/*', GLOB_ONLYDIR) ?: []),
            ];
            if (isset($environment['DB_DATABASE'])) {
                $connect($safeName($environment['DB_DATABASE']));
                $connection = DB::connection('lifecycle');
                $result['counts'] = [];
                foreach (['users', 'workspaces', 'projects', 'keywords', 'keyword_clusters', 'jobs', 'migrations'] as $table) {
                    $result['counts'][$table] = $connection->getSchemaBuilder()->hasTable($table) ? $connection->table($table)->count() : null;
                }
                $result['project_columns'] = $connection->getSchemaBuilder()->hasTable('projects')
                    ? array_values(array_filter($connection->getSchemaBuilder()->getColumnListing('projects'), static fn (string $column): bool => str_starts_with($column, 'lifecycle_')))
                    : [];
            }
            $out($result);

        default:
            fwrite(STDERR, "Unknown command.\n");
            exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
