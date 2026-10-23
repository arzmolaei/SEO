<?php

namespace Tests\Unit\Exports;

use App\Services\Exports\ProjectSeoExportSchema;
use App\Support\Exports\SpreadsheetCsvWriter;
use PHPUnit\Framework\TestCase;

class SpreadsheetCsvWriterTest extends TestCase
{
    public function test_begin_writes_utf8_bom_exact_v1_header_and_crlf(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        $writer = new SpreadsheetCsvWriter($stream);
        $writer->begin(ProjectSeoExportSchema::HEADERS);
        $writer->rewind();

        $bytes = stream_get_contents($stream);
        $this->assertIsString($bytes);
        $this->assertSame("\xEF\xBB\xBF".implode(',', ProjectSeoExportSchema::HEADERS)."\r\n", $bytes);
        $this->assertSame(0, $writer->rowCount());
        $this->assertSame(hash('sha256', $bytes), $writer->sha256());
    }

    public function test_write_uses_rfc_csv_escaping_and_deterministic_scalars(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        $writer = new SpreadsheetCsvWriter($stream);
        $writer->begin(['plain', 'comma', 'quote', 'newline', 'null', 'true', 'false', 'integer', 'decimal']);
        $writer->write([
            'سئو',
            'الف,ب',
            'او گفت "سلام"',
            "خط اول\nخط دوم",
            null,
            true,
            false,
            42,
            12.5,
        ]);
        $writer->rewind();

        $bytes = stream_get_contents($stream);
        $this->assertIsString($bytes);
        $this->assertSame(
            "\xEF\xBB\xBFplain,comma,quote,newline,null,true,false,integer,decimal\r\n"
            ."سئو,\"الف,ب\",\"او گفت \"\"سلام\"\"\",\"خط اول\nخط دوم\",,1,0,42,12.5\r\n",
            $bytes,
        );
        $this->assertSame(1, $writer->rowCount());
        $this->assertSame(hash('sha256', $bytes), $writer->sha256());

        $withoutRows = str_replace("\r\n", '', $bytes);
        $this->assertStringNotContainsString("\r", $withoutRows);
        $this->assertSame(1, substr_count($withoutRows, "\n"));
    }

    public function test_sha256_can_be_read_repeatedly_without_changing_digest_state(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        $writer = new SpreadsheetCsvWriter($stream);
        $writer->begin(['value']);
        $firstDigest = $writer->sha256();
        $writer->write(['بعد']);
        $writer->rewind();

        $bytes = stream_get_contents($stream);
        $this->assertIsString($bytes);
        $this->assertNotSame($firstDigest, $writer->sha256());
        $this->assertSame(hash('sha256', $bytes), $writer->sha256());
        $this->assertSame(hash('sha256', $bytes), $writer->sha256());
    }
}
