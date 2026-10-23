<?php

namespace App\Services\Backup;

use RuntimeException;
use Throwable;

final class BackupException extends RuntimeException
{
    public function __construct(
        private readonly string $codeName,
        private readonly string $userMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function codeName(): string
    {
        return $this->codeName;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
