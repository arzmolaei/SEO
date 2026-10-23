<?php

namespace App\Services\Backup;

final class RestorePreview
{
    public function __construct(
        private readonly string $uploadId,
        private readonly string $digest,
        private readonly string $sourceInstallationId,
        private readonly string $sourceCreatedAtUtc,
        private readonly string $sourceProductVersion,
        private readonly int $tableRecordCount,
        private readonly int $durableFileCount,
        private readonly int $bundleBytes,
    ) {
        if (
            preg_match('/\A[a-f0-9]{32}\z/', $this->uploadId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $this->digest) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $this->sourceInstallationId) !== 1
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $this->sourceCreatedAtUtc) !== 1
            || trim($this->sourceProductVersion) === ''
            || strtolower(trim($this->sourceProductVersion)) === 'dev'
            || $this->tableRecordCount < 0
            || $this->durableFileCount < 0
            || $this->bundleBytes < 1
        ) {
            throw new BackupException(
                'restore_invalid_bundle',
                'فایل پشتیبان معتبر نیست.',
            );
        }
    }

    public function uploadId(): string
    {
        return $this->uploadId;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function sourceInstallationId(): string
    {
        return $this->sourceInstallationId;
    }

    public function sourceCreatedAtUtc(): string
    {
        return $this->sourceCreatedAtUtc;
    }

    public function sourceProductVersion(): string
    {
        return $this->sourceProductVersion;
    }

    public function tableRecordCount(): int
    {
        return $this->tableRecordCount;
    }

    public function durableFileCount(): int
    {
        return $this->durableFileCount;
    }

    public function bundleBytes(): int
    {
        return $this->bundleBytes;
    }

    /** @return array{upload_id:string,digest:string,source_installation_id:string,source_created_at_utc:string,source_product_version:string,table_record_count:int,durable_file_count:int,bundle_bytes:int} */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'digest' => $this->digest,
            'source_installation_id' => $this->sourceInstallationId,
            'source_created_at_utc' => $this->sourceCreatedAtUtc,
            'source_product_version' => $this->sourceProductVersion,
            'table_record_count' => $this->tableRecordCount,
            'durable_file_count' => $this->durableFileCount,
            'bundle_bytes' => $this->bundleBytes,
        ];
    }
}
