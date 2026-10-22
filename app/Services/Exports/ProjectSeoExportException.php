<?php

namespace App\Services\Exports;

use RuntimeException;
use Throwable;

final class ProjectSeoExportException extends RuntimeException
{
    private function __construct(
        private readonly string $codeName,
        private readonly string $operatorMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($codeName, 0, $previous);
    }

    public static function currentIntent(): self
    {
        return new self(
            'export_integrity_current_intent',
            'خروجی به‌دلیل ناسازگاری داده‌های نیت قابل تولید نیست.',
        );
    }

    public static function projectRelation(): self
    {
        return new self(
            'export_integrity_project_relation',
            'خروجی به‌دلیل ناسازگاری داده‌های پروژه قابل تولید نیست.',
        );
    }

    public static function tempStreamUnavailable(?Throwable $previous = null): self
    {
        return new self(
            'export_temp_stream_unavailable',
            'فضای موقت امن برای تولید خروجی در دسترس نیست.',
            $previous,
        );
    }

    public static function generationFailed(?Throwable $previous = null): self
    {
        return new self(
            'export_generation_failed',
            'تولید خروجی انجام نشد. دوباره تلاش کنید.',
            $previous,
        );
    }

    public function codeName(): string
    {
        return $this->codeName;
    }

    public function userMessage(): string
    {
        return $this->operatorMessage;
    }
}
