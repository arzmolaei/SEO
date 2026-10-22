<?php

namespace App\Contracts;

interface SystemProbe
{
    public function phpVersion(): string;

    public function extensionLoaded(string $extension): bool;

    public function pathIsWritable(string $path): bool;

    public function databaseReachable(): bool;

    public function databaseDriver(): string;

    public function queueConnection(): string;

    public function schedulerSupported(): bool;

    public function freeDiskBytes(string $path): ?int;

    public function appKeyConfigured(): bool;

    public function productionAssetsPresent(): bool;
}
