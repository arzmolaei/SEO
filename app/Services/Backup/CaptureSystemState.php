<?php

namespace App\Services\Backup;

use App\Support\Backup\BackupBundleWriter;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\ProductVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

final class CaptureSystemState
{
    public function __construct(
        private readonly ApplicationTableCatalog $catalog,
        private readonly ApplicationSchemaFingerprint $fingerprint,
        private readonly DatabaseRowCodec $codec,
        private readonly BackupBundleWriter $writer,
        private readonly ProductVersion $productVersion,
        private readonly InstallationState $installationState,
    ) {}

    public function handle(LifecycleMutexHandle $ownership): CapturedSystemState
    {
        $ownership->assertActive();
        $streams = [];
        $output = null;
        $connection = DB::connection();
        $transactionStarted = false;

        try {
            $details = $this->installationState->details();
            if ($details === null) {
                throw $this->integrityFailure();
            }

            $productVersion = $this->productVersion->requireDistributable();
            if (($details['product_version'] ?? null) !== $productVersion) {
                throw $this->integrityFailure();
            }

            $installationId = $this->requiredString($details, 'installation_id');
            $installerSchemaVersion = $this->requiredPositiveInt($details, 'installer_schema_version');
            $ownerUserId = $this->requiredPositiveInt($details, 'owner_user_id');
            $tables = $this->catalog->metadata($connection);
            $schemaFingerprint = $this->fingerprint->forConnection($connection);

            $this->beginConsistentTransaction($connection);
            $transactionStarted = true;

            $entries = [];
            foreach ($tables as $table) {
                [$stream, $recordCount] = $this->captureTable($connection, $table);
                $streams[] = $stream;
                $entries[] = [
                    'path' => $this->catalog->entryPath($table['name']),
                    'stream' => $stream,
                    'media_type' => 'application/x-ndjson',
                    'record_count' => $recordCount,
                ];
            }

            $settingsStream = $this->newTempStream();
            try {
                $this->writeAll($settingsStream, json_encode([
                    'APP_KEY' => (string) config('app.key'),
                    'APP_LOCALE' => (string) config('app.locale'),
                    'APP_FALLBACK_LOCALE' => (string) config('app.fallback_locale'),
                    'SELF_HOST_REGISTRATION' => (bool) config('installer.public_registration', false),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                if (! rewind($settingsStream)) {
                    throw $this->integrityFailure();
                }
            } catch (Throwable $exception) {
                fclose($settingsStream);
                throw $exception;
            }
            $streams[] = $settingsStream;
            $entries[] = [
                'path' => 'settings/runtime.json',
                'stream' => $settingsStream,
                'media_type' => 'application/json',
            ];

            foreach ($this->captureDurableFiles() as $fileEntry) {
                $streams[] = $fileEntry['stream'];
                $entries[] = $fileEntry;
            }

            $connection->commit();
            $transactionStarted = false;

            $output = $this->newTempStream();
            $manifest = $this->writer->write($output, [
                'schema' => BackupManifest::SCHEMA,
                'created_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'product_version' => $productVersion,
                'installer_schema_version' => $installerSchemaVersion,
                'source_installation_id' => $installationId,
                'source_owner_user_id' => $ownerUserId,
                'source_database_driver' => $connection->getDriverName(),
                'source_database_server_version' => $this->databaseServerVersion($connection),
                'application_schema_fingerprint' => $schemaFingerprint,
                'tables' => $tables,
                'excluded_runtime_tables' => $this->catalog->excludedTables(),
            ], $entries);

            $this->closeStreams($streams, null);
            $streams = [];
            if (! rewind($output)) {
                throw $this->integrityFailure();
            }

            return new CapturedSystemState($output, $manifest);
        } catch (BackupException $exception) {
            if ($transactionStarted && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->closeStreams($streams, $output);

            throw $exception;
        } catch (Throwable $exception) {
            if ($transactionStarted && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->closeStreams($streams, $output);

            throw new BackupException(
                'backup_generation_failed',
                'تولید فایل پشتیبان انجام نشد. دوباره تلاش کنید.',
                $exception,
            );
        }
    }

    /**
     * @param  array{name:string,columns:list<array{name:string,family:string}>}  $table
     * @return array{resource, int}
     */
    private function captureTable(Connection $connection, array $table): array
    {
        $stream = $this->newTempStream();

        try {
            $families = [];
            $batchSize = max(1, (int) config('backup.capture.batch_size', 250));
            $afterId = null;
            $count = 0;

            while (true) {
                $query = $connection->table($table['name']);

                foreach ($table['columns'] as $column) {
                    $name = $column['name'];
                    $family = $column['family'];
                    $families[$name] = $family;

                    if ($family === 'decimal' || $family === 'float') {
                        $wrapped = $connection->getQueryGrammar()->wrap($name);
                        $castType = $connection->getDriverName() === 'sqlite' ? 'TEXT' : 'CHAR';
                        $query->addSelect(new Expression("CAST({$wrapped} AS {$castType}) AS {$wrapped}"));
                    } else {
                        $query->addSelect($name);
                    }
                }

                $query->orderBy('id')->limit($batchSize);
                if ($afterId !== null) {
                    $query->where('id', '>', $afterId);
                }

                $rows = $query->get();
                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows as $row) {
                    $raw = (array) $row;
                    $encoded = $this->codec->encodeRow($raw, $families);
                    $this->writeAll($stream, json_encode(
                        $encoded,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    )."\n");
                    $afterId = (int) $raw['id'];
                    $count++;
                }

                if ($rows->count() < $batchSize) {
                    break;
                }
            }

            if (! rewind($stream)) {
                throw $this->integrityFailure();
            }

            return [$stream, $count];
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    /** @return list<array{path:string,stream:resource,media_type:string}> */
    private function captureDurableFiles(): array
    {
        $configured = config('backup.durable_file_roots', []);
        if (! is_array($configured)) {
            throw $this->integrityFailure();
        }

        $roots = [];
        foreach ($configured as $archiveRoot => $filesystemRoot) {
            if (! is_string($archiveRoot) || ! is_string($filesystemRoot)) {
                throw $this->integrityFailure();
            }
            $roots[$archiveRoot] = $filesystemRoot;
        }
        ksort($roots, SORT_STRING);

        $entries = [];

        try {
            foreach ($roots as $archiveRoot => $filesystemRoot) {
                if (! file_exists($filesystemRoot) && ! is_link($filesystemRoot)) {
                    continue;
                }
                if (is_link($filesystemRoot) || ! is_dir($filesystemRoot)) {
                    throw $this->integrityFailure();
                }

                foreach ($this->durableFilesUnder($filesystemRoot) as $relativePath => $absolutePath) {
                    $entries[] = [
                        'path' => rtrim($archiveRoot, '/').'/'.$relativePath,
                        'stream' => $this->captureFile($absolutePath),
                        'media_type' => 'application/octet-stream',
                    ];
                }
            }

            return $entries;
        } catch (Throwable $exception) {
            foreach ($entries as $entry) {
                if (is_resource($entry['stream'])) {
                    fclose($entry['stream']);
                }
            }

            throw $exception;
        }
    }

    /** @return resource */
    private function captureFile(string $absolutePath)
    {
        $stream = $this->newTempStream();
        $source = fopen($absolutePath, 'rb');

        if ($source === false) {
            fclose($stream);
            throw $this->integrityFailure();
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 64 * 1024);
                if ($chunk === false) {
                    throw $this->integrityFailure();
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeAll($stream, $chunk);
            }

            if (! rewind($stream)) {
                throw $this->integrityFailure();
            }

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        } finally {
            fclose($source);
        }
    }

    /** @return array<string, string> */
    private function durableFilesUnder(string $root): array
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw $this->integrityFailure();
        }

        $files = [];
        $pending = [['absolute' => $root, 'relative' => '']];

        while ($pending !== []) {
            $directory = array_pop($pending);

            $items = scandir($directory['absolute']);
            if ($items === false) {
                throw $this->integrityFailure();
            }
            $items = array_values(array_diff($items, ['.', '..']));
            sort($items, SORT_STRING);

            foreach ($items as $item) {
                $absolute = $directory['absolute'].'/'.$item;
                $relative = $directory['relative'] === '' ? $item : $directory['relative'].'/'.$item;

                if (is_link($absolute)) {
                    throw $this->integrityFailure();
                }

                if (is_dir($absolute)) {
                    $pending[] = ['absolute' => $absolute, 'relative' => $relative];

                    continue;
                }

                if (! is_file($absolute)) {
                    throw $this->integrityFailure();
                }

                $real = realpath($absolute);
                if ($real === false || ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
                    throw $this->integrityFailure();
                }

                $files[$relative] = $absolute;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function beginConsistentTransaction(Connection $connection): void
    {
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $connection->beginTransaction();
    }

    private function databaseServerVersion(Connection $connection): string
    {
        try {
            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            $version = null;
        }

        if (is_scalar($version) && trim((string) $version) !== '') {
            return (string) $version;
        }

        return $connection->getDriverName().'-unknown';
    }

    /** @return resource */
    private function newTempStream()
    {
        $maxMemory = max(1, (int) config('backup.capture.temp_memory_bytes', 2 * 1024 * 1024));
        $stream = fopen("php://temp/maxmemory:{$maxMemory}", 'w+b');

        if ($stream === false) {
            throw new BackupException(
                'backup_temp_unavailable',
                'فضای موقت امن برای تولید پشتیبان در دسترس نیست.',
            );
        }

        return $stream;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw $this->integrityFailure();
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $details */
    private function requiredString(array $details, string $key): string
    {
        $value = $details[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw $this->integrityFailure();
        }

        return $value;
    }

    /** @param array<string, mixed> $details */
    private function requiredPositiveInt(array $details, string $key): int
    {
        $value = $details[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw $this->integrityFailure();
        }

        return $value;
    }

    /**
     * @param  list<resource>  $streams
     * @param  resource|null  $output
     */
    private function closeStreams(array $streams, $output): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (is_resource($output)) {
            fclose($output);
        }
    }

    private function integrityFailure(): BackupException
    {
        return new BackupException(
            'backup_integrity_failed',
            'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
        );
    }
}
