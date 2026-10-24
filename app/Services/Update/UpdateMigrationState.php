<?php

namespace App\Services\Update;

final class UpdateMigrationState
{
    /** @var list<array{migration:string,batch:int}> */
    public readonly array $ran;

    /** @var list<array{migration:string,batch:int,schema_fingerprint_sha256:string}> */
    public readonly array $appliedSuffix;

    public readonly string $digestSha256;

    /**
     * @param  array<array-key, mixed>  $ran
     * @param  array<array-key, mixed>  $appliedSuffix
     */
    public function __construct(
        array $ran,
        public readonly string $schemaFingerprintSha256,
        array $appliedSuffix = [],
    ) {
        $this->assertDigest($schemaFingerprintSha256);
        $this->ran = $this->normalizeRows($ran);
        if (! array_is_list($appliedSuffix)) {
            throw self::invalidState();
        }
        $suffix = [];
        foreach ($appliedSuffix as $row) {
            if (! is_array($row) || count($row) !== 3 || ! is_string($row['schema_fingerprint_sha256'] ?? null)) {
                throw self::invalidState();
            }
            $this->assertDigest($row['schema_fingerprint_sha256']);
            $normalized = $this->normalizeRows([['migration' => $row['migration'] ?? null, 'batch' => $row['batch'] ?? null]]);
            $suffix[] = [...$normalized[0], 'schema_fingerprint_sha256' => $row['schema_fingerprint_sha256']];
        }
        $suffixRows = array_map(static fn (array $row): array => ['migration' => $row['migration'], 'batch' => $row['batch']], $suffix);
        if ($suffix !== [] && (
            array_slice($this->ran, -count($suffix)) !== $suffixRows
            || $suffix[array_key_last($suffix)]['schema_fingerprint_sha256'] !== $schemaFingerprintSha256
        )) {
            throw self::invalidState();
        }
        $this->appliedSuffix = $suffix;
        $this->digestSha256 = hash('sha256', json_encode($this->ran, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'seo-update-migration-state/1',
            'ran' => $this->ran,
            'digest_sha256' => $this->digestSha256,
            'schema_fingerprint_sha256' => $this->schemaFingerprintSha256,
            'applied_suffix' => $this->appliedSuffix,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 5 || ($data['schema'] ?? null) !== 'seo-update-migration-state/1'
            || ! is_array($data['ran'] ?? null) || ! array_is_list($data['ran'])
            || ! is_array($data['applied_suffix'] ?? null) || ! array_is_list($data['applied_suffix'])
            || ! is_string($data['schema_fingerprint_sha256'] ?? null) || ! is_string($data['digest_sha256'] ?? null)
        ) {
            throw self::invalidState();
        }
        $state = new self($data['ran'], $data['schema_fingerprint_sha256'], $data['applied_suffix']);
        if (! hash_equals($state->digestSha256, $data['digest_sha256'])) {
            throw self::invalidState();
        }

        return $state;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array{migration:string,batch:int}>
     */
    private function normalizeRows(array $rows): array
    {
        if (! array_is_list($rows)) {
            throw self::invalidState();
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (
                ! is_array($row) || count($row) !== 2
                || ! is_string($row['migration'] ?? null) || preg_match('/\A[a-zA-Z0-9_]{1,255}\z/', $row['migration']) !== 1
                || ! is_int($row['batch'] ?? null) || $row['batch'] < 1 || isset($seen[$row['migration']])
            ) {
                throw self::invalidState();
            }
            $seen[$row['migration']] = true;
            $result[] = ['migration' => $row['migration'], 'batch' => $row['batch']];
        }

        return $result;
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw self::invalidState();
        }
    }

    private static function invalidState(): UpdateException
    {
        return new UpdateException('update_recovery_required', 'تاریخچه یا ساختار داده برای ادامه ایمن مهاجرت قابل اثبات نیست.');
    }
}
