<?php

namespace App\Services\Update;

use App\Support\InstallationState;
use App\Support\Update\UpdatePackageFormat;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateRuntimeIdentity
{
    public function __construct(private readonly InstallationState $installationState) {}

    /** @return array<string, string> */
    public function capture(): array
    {
        try {
            $metadata = $this->installationState->details();
            $key = config('app.key');
            $cipher = config('app.cipher');
            $environment = app()->environmentFilePath();
            $configuration = DB::connection()->getConfig();
            if (
                $metadata === null || ! is_string($key) || $key === '' || ! is_string($cipher) || $cipher === ''
                || ! is_array($configuration) || is_link($environment) || ! is_file($environment)
            ) {
                throw $this->invalidState();
            }
            $environmentDigest = hash_file('sha256', $environment);
            if (! is_string($environmentDigest)) {
                throw $this->invalidState();
            }
            // Only this field changes during successful target finalization.
            unset($metadata['product_version']);

            return [
                'runtime_key_sha256' => hash('sha256', $key),
                'crypto_configuration_sha256' => hash('sha256', UpdatePackageFormat::canonicalMetadataJson(['cipher' => $cipher, 'key_sha256' => hash('sha256', $key)])),
                'environment_sha256' => $environmentDigest,
                'database_configuration_sha256' => hash('sha256', UpdatePackageFormat::canonicalMetadataJson($configuration)),
                'installation_identity_sha256' => hash('sha256', UpdatePackageFormat::canonicalMetadataJson($metadata)),
            ];
        } catch (UpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->invalidState($exception);
        }
    }

    private function invalidState(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_invalid_state', 'هویت نصب و تنظیمات پایدار برای به‌روزرسانی قابل تأیید نیست.', $previous);
    }
}
