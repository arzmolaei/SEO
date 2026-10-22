<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\StreamingCsvReader;
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
}
