<?php

namespace App\Support\Exports;

final class SpreadsheetText
{
    public static function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)
            ? "'".$value
            : $value;
    }

    /** @param array<string, mixed> $fields */
    public static function exactJson(array $fields): string
    {
        return json_encode(
            $fields,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
