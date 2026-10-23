<?php

namespace App\Services\Backup;

use Throwable;

final class EnvironmentSettingsUpdater
{
    private const ALLOWED_KEYS = [
        'APP_KEY',
        'APP_LOCALE',
        'APP_FALLBACK_LOCALE',
        'SELF_HOST_REGISTRATION',
    ];

    private readonly string $environmentPath;

    public function __construct(?string $environmentPath = null)
    {
        $this->environmentPath = $environmentPath ?? base_path('.env');
    }

    /** @param  array<string, mixed>  $settings */
    public function apply(array $settings): void
    {
        $validated = $this->validate($settings);

        $contents = @file_get_contents($this->environmentPath);
        if ($contents === false) {
            throw $this->writeFailed();
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        foreach ($validated as $key => $value) {
            $encoded = $this->encode($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $replaced = preg_replace($pattern, $key.'='.$encoded, $contents);
                if (! is_string($replaced)) {
                    throw $this->writeFailed();
                }
                $contents = $replaced;

                continue;
            }

            if ($contents !== '' && ! str_ends_with($contents, "\n") && ! str_ends_with($contents, "\r")) {
                $contents .= $newline;
            }

            $contents .= $key.'='.$encoded.$newline;
        }

        $temporaryPath = $this->environmentPath.'.tmp';

        try {
            @unlink($temporaryPath);

            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw $this->writeFailed();
            }

            if (! @chmod($temporaryPath, 0600)) {
                throw $this->writeFailed();
            }

            if (! @rename($temporaryPath, $this->environmentPath)) {
                throw $this->writeFailed();
            }

            @chmod($this->environmentPath, 0600);
        } catch (BackupException $exception) {
            @unlink($temporaryPath);

            throw $exception;
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            throw $this->writeFailed($exception);
        }

        config([
            'app.key' => $validated['APP_KEY'],
            'app.locale' => $validated['APP_LOCALE'],
            'app.fallback_locale' => $validated['APP_FALLBACK_LOCALE'],
            'installer.public_registration' => $validated['SELF_HOST_REGISTRATION'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{APP_KEY: string, APP_LOCALE: string, APP_FALLBACK_LOCALE: string, SELF_HOST_REGISTRATION: bool}
     */
    private function validate(array $settings): array
    {
        $keys = array_keys($settings);
        sort($keys, SORT_STRING);
        $allowed = self::ALLOWED_KEYS;
        sort($allowed, SORT_STRING);

        if ($keys !== $allowed) {
            throw $this->invalidSettings();
        }

        $appKey = $settings['APP_KEY'] ?? null;
        $locale = $settings['APP_LOCALE'] ?? null;
        $fallbackLocale = $settings['APP_FALLBACK_LOCALE'] ?? null;
        $registration = $settings['SELF_HOST_REGISTRATION'] ?? null;

        if (
            ! is_string($appKey)
            || trim($appKey) === ''
            || ! is_string($locale)
            || trim($locale) === ''
            || ! is_string($fallbackLocale)
            || trim($fallbackLocale) === ''
            || ! is_bool($registration)
        ) {
            throw $this->invalidSettings();
        }

        return [
            'APP_KEY' => $appKey,
            'APP_LOCALE' => $locale,
            'APP_FALLBACK_LOCALE' => $fallbackLocale,
            'SELF_HOST_REGISTRATION' => $registration,
        ];
    }

    private function encode(string|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '"'.str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        ).'"';
    }

    private function invalidSettings(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'تنظیمات بازیابی داخل فایل پشتیبان معتبر نیست.',
        );
    }

    private function writeFailed(?Throwable $previous = null): BackupException
    {
        return new BackupException(
            'restore_environment_update_failed',
            'به‌روزرسانی امن تنظیمات بازیابی انجام نشد.',
            $previous,
        );
    }
}
