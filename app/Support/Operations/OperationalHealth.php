<?php

namespace App\Support\Operations;

class OperationalHealth
{
    public function __construct(
        private readonly SchedulerHeartbeat $schedulerHeartbeat,
        private readonly QueueProbe $queueProbe,
        private readonly ?string $phpBinary = null,
        private readonly ?string $artisanPath = null,
    ) {}

    /**
     * @return array{
     *   scheduler: array{state: 'verified'|'warning'|'fail', observed_at: ?string},
     *   queue: array{state: 'verified'|'warning'|'fail', completed_at: ?string},
     *   instructions: array{scheduler: string, worker: string},
     *   blocking: bool
     * }
     */
    public function snapshot(): array
    {
        $scheduler = $this->schedulerHeartbeat->status();
        $latestProbeId = $this->queueProbe->latestId();
        $queue = $latestProbeId === null
            ? ['state' => $this->queueProbe->storageState(), 'completed_at' => null]
            : $this->queueProbe->status($latestProbeId);

        $php = $this->quote($this->phpBinary ?? PHP_BINARY);
        $artisan = $this->quote($this->artisanPath ?? base_path('artisan'));

        return [
            'scheduler' => $scheduler,
            'queue' => $queue,
            'instructions' => [
                'scheduler' => "* * * * * {$php} {$artisan} schedule:run >/dev/null 2>&1",
                'worker' => "* * * * * {$php} {$artisan} queue:work database --stop-when-empty --max-time=50 --tries=3 --no-interaction >/dev/null 2>&1",
            ],
            'blocking' => $scheduler['state'] === 'fail' || $queue['state'] === 'fail',
        ];
    }

    private function quote(string $value): string
    {
        if ($value !== '' && preg_match('/\A[A-Za-z0-9_\/.:-]+\z/', $value) === 1) {
            return $value;
        }

        return escapeshellarg($value);
    }
}
