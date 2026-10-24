<?php

namespace App\Services\Update;

final readonly class PreparedSystemUpdate
{
    public function __construct(
        public string $uploadId,
        public string $packageDigestSha256,
        public string $sourceReleaseKind,
        public ?string $sourceReleaseId,
        public string $sourceProductVersion,
        public string $sourceSchemaFingerprint,
        public string $targetReleaseId,
        public string $targetReleaseRoot,
        public string $targetProductVersion,
        public string $targetSchemaFingerprint,
        public string $safetyBundlePath,
        public string $safetyMetadataPath,
        public string $preUpdateMigrationHistorySha256,
    ) {}
}
