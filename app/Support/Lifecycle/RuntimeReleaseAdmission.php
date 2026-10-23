<?php

namespace App\Support\Lifecycle;

use App\Support\Update\ActiveReleasePointer;
use RuntimeException;

final class RuntimeReleaseAdmission
{
    public function __construct(
        private readonly ?ActiveReleasePointer $pointer = null,
        private readonly ?string $bootedReleaseRoot = null,
    ) {}

    public function assertCurrent(): void
    {
        $booted = realpath($this->bootedReleaseRoot ?? base_path());
        $current = ($this->pointer ?? new ActiveReleasePointer)->current();
        if ($booted === false || ($current['release_root'] ?? null) !== $booted) {
            throw new RuntimeException('The running release is no longer active.');
        }
    }
}
