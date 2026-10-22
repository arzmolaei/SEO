<?php

namespace App\Support;

use App\Contracts\SystemProbe;
use App\Support\Installer\InstallerPreflight;
use App\Support\Operations\OperationalHealth;

class SystemHealthChecker
{
    public function __construct(
        private readonly SystemProbe $probe,
        private readonly OperationalHealth $operationalHealth,
    ) {}

    /**
     * @return array{
     *   ready: bool,
     *   checks: array<string, array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}>
     * }
     */
    public function check(): array
    {
        $preflight = (new InstallerPreflight($this->probe))->check();
        $checks = $preflight['checks'];
        $checks['database_extension'] = $this->databaseExtensionCheck();
        $checks['database'] = $this->databaseCheck();

        $operations = $this->operationalHealth->snapshot();
        $checks['queue'] = $this->queueCheck($operations['queue']);
        $checks['scheduler'] = $this->schedulerCheck($operations['scheduler']);

        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] === 'fail') {
                return ['ready' => false, 'checks' => $checks];
            }
        }

        return ['ready' => true, 'checks' => $checks];
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function databaseExtensionCheck(): array
    {
        $driver = $this->probe->databaseDriver();
        $extension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => null,
        };

        if ($extension === null) {
            return $this->result('fail', true, $driver, 'Configured database driver is not supported.');
        }

        $loaded = $this->probe->extensionLoaded($extension);

        return $this->result(
            $loaded ? 'pass' : 'fail',
            true,
            ['driver' => $driver, 'extension' => $extension],
            $loaded ? 'Database PDO driver extension is available.' : 'Database PDO driver extension is missing.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function databaseCheck(): array
    {
        $reachable = $this->probe->databaseReachable();

        return $this->result(
            $reachable ? 'pass' : 'fail',
            true,
            $this->probe->databaseDriver(),
            $reachable ? 'Database connection is available.' : 'Database connection could not be established.',
        );
    }

    /**
     * @param  array{state: 'verified'|'warning'|'fail', completed_at: ?string}  $evidence
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function queueCheck(array $evidence): array
    {
        return match ($evidence['state']) {
            'verified' => $this->result(
                'pass',
                true,
                $evidence['completed_at'],
                'A recent queue probe completed successfully.',
            ),
            'warning' => $this->result(
                'warning',
                true,
                $evidence['completed_at'],
                'No completed queue probe has been observed recently.',
            ),
            'fail' => $this->result(
                'fail',
                true,
                $evidence['completed_at'],
                'Queue operational evidence indicates a blocking failure.',
            ),
        };
    }

    /**
     * @param  array{state: 'verified'|'warning'|'fail', observed_at: ?string}  $evidence
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function schedulerCheck(array $evidence): array
    {
        return match ($evidence['state']) {
            'verified' => $this->result(
                'pass',
                true,
                $evidence['observed_at'],
                'A recent scheduler heartbeat has been observed.',
            ),
            'warning' => $this->result(
                'warning',
                true,
                $evidence['observed_at'],
                'No recent scheduler heartbeat has been observed.',
            ),
            'fail' => $this->result(
                'fail',
                true,
                $evidence['observed_at'],
                'Scheduler operational evidence indicates a blocking failure.',
            ),
        };
    }

    /**
     * @param  'pass'|'warning'|'fail'  $status
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function result(string $status, bool $required, mixed $value, string $message): array
    {
        return compact('status', 'required', 'value', 'message');
    }
}
