<?php

namespace App\Services\Update;

use RuntimeException;
use Throwable;

final class UpdateException extends RuntimeException
{
    public function __construct(
        private readonly string $codeName,
        private readonly string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function codeName(): string
    {
        return $this->codeName;
    }

    public function userMessage(): string
    {
        return $this->safeMessage;
    }
}
