<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\KeywordRowReader;
use App\Domain\Keywords\StagedKeywordRowValidator;
use App\Models\ImportBatch;

class ProcessKeywordCsvImport
{
    public function __construct(
        private KeywordRowReader $reader,
        private StagedKeywordRowValidator $rowValidator,
    ) {}

    public function handle(ImportBatch $batch, string $path, int $chunkSize = 500): ImportBatch
    {
        $stager = new ResumableKeywordImportStager($this->rowValidator);

        return $stager->handle(
            batch: $batch,
            rows: $this->reader->rows($path),
            checkpointSize: $chunkSize,
        );
    }
}
