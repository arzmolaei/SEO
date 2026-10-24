<?php

namespace App\Services\Update;

final readonly class UpdateHealthReport
{
    public bool $healthy;

    /** @param list<string> $failures */
    public function __construct(public array $failures)
    {
        $this->healthy = $failures === [];
    }

    /** @return array{healthy:bool,failures:list<string>} */
    public function toArray(): array
    {
        return ['healthy' => $this->healthy, 'failures' => $this->failures];
    }
}
