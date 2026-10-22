<?php

namespace App\Jobs;

use App\Support\Operations\QueueProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $probeId) {}

    public function handle(QueueProbe $queueProbe): void
    {
        $queueProbe->complete($this->probeId);
    }
}
