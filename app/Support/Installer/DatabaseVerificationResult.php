<?php

namespace App\Support\Installer;

final readonly class DatabaseVerificationResult
{
    /**
     * @param  array{driver: string, host: string, port: int, database: string}  $facts
     */
    public function __construct(
        public bool $verified,
        public ?string $code,
        public string $message,
        public ?string $fingerprint,
        public array $facts,
    ) {}

    /**
     * @param  array{driver: string, host: string, port: int, database: string}  $facts
     */
    public static function success(string $fingerprint, array $facts): self
    {
        return new self(
            verified: true,
            code: null,
            message: 'Database connection and installer capability probe succeeded.',
            fingerprint: $fingerprint,
            facts: $facts,
        );
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string}  $facts
     */
    public static function failure(string $code, string $message, array $facts): self
    {
        return new self(
            verified: false,
            code: $code,
            message: $message,
            fingerprint: null,
            facts: $facts,
        );
    }
}
