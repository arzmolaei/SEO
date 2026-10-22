#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/fonts/install-private-fonts.php <iransans-zip> <yekan-bakh-zip>\n");
    exit(1);
}

if (! class_exists(ZipArchive::class) && ! commandExists('unzip')) {
    fwrite(STDERR, "Either PHP ZipArchive or the unzip command is required.\n");
    exit(1);
}

[$script, $iranSansZip, $yekanBakhZip] = $argv;
unset($script);

$root = dirname(__DIR__, 2);
$targetRoot = $root.'/public/fonts/private';

$packages = [
    [
        'zip' => $iranSansZip,
        'target' => $targetRoot.'/iransansx',
        'files' => [
            'IRANSansXFaNum-Light.woff2',
            'IRANSansXFaNum-Regular.woff2',
            'IRANSansXFaNum-Medium.woff2',
            'IRANSansXFaNum-DemiBold.woff2',
            'IRANSansXFaNum-Bold.woff2',
            'IRANSansXFaNum-ExtraBold.woff2',
            'IRANSansXFaNum-Black.woff2',
        ],
    ],
    [
        'zip' => $yekanBakhZip,
        'target' => $targetRoot.'/yekan-bakh',
        'files' => [
            'YekanBakhFaNum-Light.woff2',
            'YekanBakhFaNum-Regular.woff2',
            'YekanBakhFaNum-SemiBold.woff2',
            'YekanBakhFaNum-Bold.woff2',
            'YekanBakhFaNum-ExtraBold.woff2',
            'YekanBakhFaNum-Black.woff2',
        ],
    ],
];

foreach ($packages as $package) {
    installFontPackage($package['zip'], $package['target'], $package['files']);
}

fwrite(STDOUT, "Private font assets installed successfully.\n");

/**
 * @param  list<string>  $requiredFiles
 */
function installFontPackage(string $zipPath, string $targetDirectory, array $requiredFiles): void
{
    if (! is_file($zipPath)) {
        throw new RuntimeException("Font ZIP not found: {$zipPath}");
    }

    if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
        throw new RuntimeException("Unable to create directory: {$targetDirectory}");
    }

    if (class_exists(ZipArchive::class)) {
        installWithZipArchive($zipPath, $targetDirectory, $requiredFiles);

        return;
    }

    installWithUnzipCommand($zipPath, $targetDirectory, $requiredFiles);
}

/**
 * @param  list<string>  $requiredFiles
 */
function installWithZipArchive(string $zipPath, string $targetDirectory, array $requiredFiles): void
{
    $zip = new ZipArchive;

    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("Unable to open ZIP: {$zipPath}");
    }

    try {
        foreach ($requiredFiles as $filename) {
            $entry = findZipArchiveEntry($zip, $filename);

            if ($entry === null) {
                throw new RuntimeException("Required font file missing from ZIP: {$filename}");
            }

            $stream = $zip->getStream($entry);

            if ($stream === false) {
                throw new RuntimeException("Unable to read font file from ZIP: {$filename}");
            }

            $target = $targetDirectory.'/'.$filename;
            $output = fopen($target, 'wb');

            if ($output === false) {
                fclose($stream);
                throw new RuntimeException("Unable to write font file: {$target}");
            }

            stream_copy_to_stream($stream, $output);
            fclose($stream);
            fclose($output);
        }
    } finally {
        $zip->close();
    }
}

/**
 * @param  list<string>  $requiredFiles
 */
function installWithUnzipCommand(string $zipPath, string $targetDirectory, array $requiredFiles): void
{
    $entries = [];
    $exitCode = 0;
    exec('unzip -Z1 '.escapeshellarg($zipPath), $entries, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Unable to inspect ZIP: {$zipPath}");
    }

    foreach ($requiredFiles as $filename) {
        $entry = findEntryByBasename($entries, $filename);

        if ($entry === null) {
            throw new RuntimeException("Required font file missing from ZIP: {$filename}");
        }

        $target = $targetDirectory.'/'.$filename;
        $command = sprintf(
            'unzip -p %s %s > %s',
            escapeshellarg($zipPath),
            escapeshellarg($entry),
            escapeshellarg($target),
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException("Unable to extract font file: {$filename}");
        }
    }
}

function findZipArchiveEntry(ZipArchive $zip, string $filename): ?string
{
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entry = $zip->getNameIndex($index);

        if ($entry !== false && basename($entry) === $filename) {
            return $entry;
        }
    }

    return null;
}

/**
 * @param  list<string>  $entries
 */
function findEntryByBasename(array $entries, string $filename): ?string
{
    foreach ($entries as $entry) {
        if (basename($entry) === $filename) {
            return $entry;
        }
    }

    return null;
}

function commandExists(string $command): bool
{
    $output = [];
    $exitCode = 0;
    exec('command -v '.escapeshellarg($command), $output, $exitCode);

    return $exitCode === 0;
}
