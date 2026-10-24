<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class LifecycleProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $destination) {}

    public function handle(): void
    {
        file_put_contents($this->destination, 'executed');
    }
}
