<?php

namespace App\Services\Exports;

use App\Models\Project;
use App\Support\Exports\SpreadsheetCsvWriter;
use App\Support\Exports\SpreadsheetText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExportProjectSeoPlan
{
    private const BATCH_SIZE = 250;

    /** @var list<string> */
    private const SPREADSHEET_TEXT_COLUMNS = [
        'project_name',
        'keyword_display_text',
        'keyword_normalized_text',
        'intent_source',
        'volume_source',
        'difficulty_source',
        'cluster_name',
        'cluster_origin',
        'page_title',
        'page_type',
        'page_url',
        'priority_volume_source',
        'priority_difficulty_source',
    ];

    public function __construct(private ProjectSeoExportSnapshot $snapshot) {}

    public function handle(Project $project, ?CarbonImmutable $generatedAt = null): ProjectSeoExportResult
    {
        $generatedAt = ($generatedAt ?? CarbonImmutable::now('UTC'))->utc();
        $stream = @fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream)) {
            throw ProjectSeoExportException::tempStreamUnavailable();
        }

        $connection = DB::connection($project->getConnectionName());
        $initialTransactionLevel = $connection->transactionLevel();

        try {
            $this->beginSnapshot($connection, $initialTransactionLevel);
            $writer = new SpreadsheetCsvWriter($stream);
            $writer->begin(ProjectSeoExportSchema::HEADERS);

            $afterId = null;
            do {
                $batch = $this->snapshot->loadBatch($project, $afterId, self::BATCH_SIZE);

                foreach ($batch['rows'] as $row) {
                    $row['schema_version'] = ProjectSeoExportSchema::ID;
                    $row['exported_at_utc'] = $generatedAt->toIso8601String();
                    $row['project_id'] = $project->id;
                    $row['project_name'] = $project->name;
                    $this->makeSpreadsheetTextSafe($row);

                    $writer->write(array_map(
                        static fn (string $header): mixed => $row[$header] ?? null,
                        ProjectSeoExportSchema::HEADERS,
                    ));
                }

                $afterId = $batch['next_after_id'];
            } while ($afterId !== null);

            $rowCount = $writer->rowCount();
            $sha256 = $writer->sha256();
            $connection->commit();
            $writer->rewind();

            return new ProjectSeoExportResult(
                stream: $stream,
                filename: $this->filename($project, $generatedAt),
                mimeType: 'text/csv; charset=UTF-8',
                rowCount: $rowCount,
                schemaVersion: ProjectSeoExportSchema::ID,
                sha256: $sha256,
                generatedAt: $generatedAt,
            );
        } catch (ProjectSeoExportException $exception) {
            $this->rollbackToLevel($connection, $initialTransactionLevel);
            fclose($stream);

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollbackToLevel($connection, $initialTransactionLevel);
            fclose($stream);

            throw ProjectSeoExportException::generationFailed($exception);
        }
    }

    private function beginSnapshot(Connection $connection, int $initialTransactionLevel): void
    {
        if ($initialTransactionLevel === 0 && $connection->getDriverName() === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $connection->beginTransaction();
    }

    /** @param array<string,mixed> $row */
    private function makeSpreadsheetTextSafe(array &$row): void
    {
        foreach (self::SPREADSHEET_TEXT_COLUMNS as $column) {
            if (($row[$column] ?? null) === null) {
                continue;
            }

            $row[$column] = SpreadsheetText::safe((string) $row[$column]);
        }
    }

    private function rollbackToLevel(Connection $connection, int $level): void
    {
        while ($connection->transactionLevel() > $level) {
            $connection->rollBack();
        }
    }

    private function filename(Project $project, CarbonImmutable $generatedAt): string
    {
        $token = preg_replace('/[^A-Za-z0-9]+/', '-', $project->name) ?? '';
        $token = strtolower(trim($token, '-'));
        if ($token === '') {
            $token = (string) $project->id;
        }

        return sprintf(
            'seo-plan-v1-%s-%s.csv',
            $token,
            $generatedAt->format('Ymd\\THis\\Z'),
        );
    }
}
