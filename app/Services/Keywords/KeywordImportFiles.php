<?php

namespace App\Services\Keywords;

use App\Models\ImportBatch;
use RuntimeException;

/** Private, never web-served location of import originals. */
final class KeywordImportFiles
{
    public function root(): string
    {
        $root = rtrim((string) config('keywords.import.storage_root'), DIRECTORY_SEPARATOR);
        $existing = $root;
        while (! file_exists($existing) && ! is_link($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if ($root === '' || ! is_dir($existing) || is_link($existing) || realpath($existing) !== $existing) {
            throw new RuntimeException('Import storage is not available.');
        }

        return $root;
    }

    public function relativePath(ImportBatch $batch): string
    {
        return $batch->project_id.'/'.$batch->id.'.csv';
    }

    public function absolutePath(ImportBatch $batch): string
    {
        $relative = (string) $batch->source_path;
        if (preg_match('#\A[0-9]+/[0-9]+\.csv\z#', $relative) !== 1 || $relative !== $this->relativePath($batch)) {
            throw new RuntimeException('Import original is not available.');
        }
        $path = $this->root().'/'.$relative;
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('Import original is not available.');
        }

        return $path;
    }

    public function delete(ImportBatch $batch): void
    {
        $path = $this->root().'/'.$this->relativePath($batch);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (! unlink($this->absolutePath($batch))) {
            throw new RuntimeException('Import original could not be deleted.');
        }
    }
}
