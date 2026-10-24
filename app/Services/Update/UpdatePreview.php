<?php

namespace App\Services\Update;

final readonly class UpdatePreview
{
    /**
     * @param  list<string>  $requiredPhpExtensions
     * @param  array<string, string>  $compatibility
     */
    public function __construct(
        public string $uploadId,
        public string $packageDigestSha256,
        public string $sourceProductVersion,
        public string $targetProductVersion,
        public string $channel,
        public string $minimumPhpVersion,
        public array $requiredPhpExtensions,
        public int $migrationCount,
        public string $migrationTreeSha256,
        public array $compatibility,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'package_digest_sha256' => $this->packageDigestSha256,
            'source_product_version' => $this->sourceProductVersion,
            'target_product_version' => $this->targetProductVersion,
            'channel' => $this->channel,
            'minimum_php_version' => $this->minimumPhpVersion,
            'required_php_extensions' => $this->requiredPhpExtensions,
            'migration_count' => $this->migrationCount,
            'migration_tree_sha256' => $this->migrationTreeSha256,
            'compatibility' => $this->compatibility,
        ];
    }
}
