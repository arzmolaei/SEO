<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\StreamingCsvReader;
use Generator;
use PHPUnit\Framework\TestCase;

class StreamingCsvReaderTest extends TestCase
{
    public function test_it_streams_utf8_bom_csv_with_quoted_commas_and_newlines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seo-os-csv-');
        $this->assertNotFalse($path);

        file_put_contents(
            $path,
            "\xEF\xBB\xBFKeyword,Search Volume,Note\n"
            .'"خرید, لپ تاپ",1200,"خط اول'."\n".'خط دوم"'."\n"
            .'خدمات سئو,800,ساده'."\n",
        );

        try {
            $rows = iterator_to_array((new StreamingCsvReader)->rows($path), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([
            [
                'Keyword' => 'خرید, لپ تاپ',
                'Search Volume' => '1200',
                'Note' => "خط اول\nخط دوم",
            ],
            [
                'Keyword' => 'خدمات سئو',
                'Search Volume' => '800',
                'Note' => 'ساده',
            ],
        ], $rows);
    }

    public function test_it_streams_utf16le_bom_csv_as_utf8_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seo-os-csv-');
        $this->assertNotFalse($path);

        $utf8 = "Keyword,Search Volume\nخدمات سئو,800\n";
        $encoded = mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');

        file_put_contents($path, "\xFF\xFE".$encoded);

        try {
            $rows = iterator_to_array((new StreamingCsvReader)->rows($path), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([
            [
                'Keyword' => 'خدمات سئو',
                'Search Volume' => '800',
            ],
        ], $rows);
    }

    public function test_it_does_not_load_a_large_csv_into_memory_before_yielding_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seo-os-csv-');
        $this->assertNotFalse($path);
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);

        fwrite($handle, "Keyword,Note\n");
        $largeCell = str_repeat('x', 1024);

        for ($index = 0; $index < 15000; $index++) {
            fwrite($handle, "keyword-{$index},{$largeCell}\n");
        }

        fclose($handle);
        unset($largeCell);
        gc_collect_cycles();

        $peakBefore = memory_get_peak_usage(true);
        $generator = (new StreamingCsvReader)->rows($path);

        $this->assertInstanceOf(Generator::class, $generator);

        $count = 0;

        try {
            foreach ($generator as $row) {
                $this->assertSame("keyword-{$count}", $row['Keyword']);
                $count++;

                if ($count === 100) {
                    break;
                }
            }
        } finally {
            @unlink($path);
        }

        $this->assertSame(100, $count);
        $this->assertLessThan(
            8 * 1024 * 1024,
            memory_get_peak_usage(true) - $peakBefore,
            'Streaming the first rows should not allocate memory proportional to the whole CSV file.',
        );
    }
}
