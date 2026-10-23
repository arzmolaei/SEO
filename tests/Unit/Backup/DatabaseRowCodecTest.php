<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseRowCodec;
use Tests\TestCase;

class DatabaseRowCodecTest extends TestCase
{
    public function test_scalar_decimal_json_binary_and_null_round_trip_without_float_coercion(): void
    {
        $codec = new DatabaseRowCodec;
        $families = [
            'id' => 'integer',
            'price' => 'decimal',
            'payload' => 'json',
            'persian_text' => 'text',
            'binary_value' => 'binary',
            'nullable_value' => 'text',
        ];
        $binary = "\x00\xFF\x10binary";
        $row = [
            'id' => 42,
            'price' => '12345678901234567890.123400',
            'payload' => '{"نیم‌فاصله":"کلمه‌ی کلیدی","ratio":1.00}',
            'persian_text' => 'کلمه‌ی کلیدی',
            'binary_value' => $binary,
            'nullable_value' => null,
        ];

        $encoded = $codec->encodeRow($row, $families);

        $this->assertSame('42', $encoded['id']);
        $this->assertSame('12345678901234567890.123400', $encoded['price']);
        $this->assertSame($row['payload'], $encoded['payload']);
        $this->assertSame($row['persian_text'], $encoded['persian_text']);
        $this->assertSame([
            '__seo_backup_binary_base64' => base64_encode($binary),
        ], $encoded['binary_value']);
        $this->assertNull($encoded['nullable_value']);

        $decoded = $codec->decodeRow($encoded, $families);

        $this->assertSame('42', $decoded['id']);
        $this->assertSame($row['price'], $decoded['price']);
        $this->assertSame($row['payload'], $decoded['payload']);
        $this->assertSame($row['persian_text'], $decoded['persian_text']);
        $this->assertSame($binary, $decoded['binary_value']);
        $this->assertNull($decoded['nullable_value']);
    }

    public function test_float_input_is_rejected_instead_of_silently_losing_database_precision(): void
    {
        try {
            (new DatabaseRowCodec)->encodeRow(['price' => 0.1], ['price' => 'decimal']);
            $this->fail('Float database value was silently accepted by the backup codec.');
        } catch (BackupException $exception) {
            $this->assertSame('backup_integrity_failed', $exception->codeName());
        }
    }

    public function test_invalid_binary_tag_is_rejected(): void
    {
        try {
            (new DatabaseRowCodec)->decodeRow([
                'binary_value' => ['__seo_backup_binary_base64' => '***not-base64***'],
            ], ['binary_value' => 'binary']);
            $this->fail('Malformed binary payload was accepted.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_invalid_bundle', $exception->codeName());
        }
    }
}
