<?php

namespace App\Support\Lifecycle;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use InvalidArgumentException;
use RuntimeException;

final class MaintenanceWindow
{
    private const OWNER = 'seo-os-lifecycle';

    public function __construct(private readonly MaintenanceMode $maintenanceMode) {}

    public function run(string $operation, Closure $callback): mixed
    {
        $operation = trim($operation);

        if ($operation === '' || strlen($operation) > 64 || preg_match('/\A[a-z0-9][a-z0-9._:-]*\z/', $operation) !== 1) {
            throw new InvalidArgumentException('Lifecycle operation name is invalid.');
        }

        if ($this->maintenanceMode->active()) {
            $data = $this->maintenanceMode->data();

            if (! $this->isLifecycleOwned($data)) {
                throw new RuntimeException('Application is already in administrator maintenance mode.');
            }

            $this->maintenanceMode->deactivate();
        }

        $ownerToken = bin2hex(random_bytes(16));
        $retry = max(1, (int) config('backup.maintenance_retry_seconds', 60));

        $this->maintenanceMode->activate([
            'time' => time(),
            'retry' => $retry,
            'seo_os_owner' => self::OWNER,
            'seo_os_operation' => $operation,
            'seo_os_owner_token' => $ownerToken,
        ]);

        try {
            return $callback();
        } finally {
            if ($this->maintenanceMode->active()) {
                $data = $this->maintenanceMode->data();

                if (
                    ($data['seo_os_owner'] ?? null) === self::OWNER
                    && ($data['seo_os_owner_token'] ?? null) === $ownerToken
                ) {
                    $this->maintenanceMode->deactivate();
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function isLifecycleOwned(array $data): bool
    {
        return ($data['seo_os_owner'] ?? null) === self::OWNER
            && is_string($data['seo_os_owner_token'] ?? null)
            && $data['seo_os_owner_token'] !== '';
    }
}
