<?php

namespace Tests\Unit\Exports;

use App\Support\Exports\SpreadsheetText;
use PHPUnit\Framework\TestCase;

class SpreadsheetTextTest extends TestCase
{
    public function test_safe_preserves_persian_and_half_space_without_normalization(): void
    {
        $value = "سئو\u{200C}سایت؛ تست";

        $this->assertSame($value, SpreadsheetText::safe($value));
        $this->assertNull(SpreadsheetText::safe(null));
    }

    public function test_safe_prefixes_apostrophe_for_formula_and_control_prefixes(): void
    {
        foreach ([
            '=SUM(1,1)',
            '+1',
            '-1',
            '@name',
            "\tvalue",
            "\rvalue",
            "\nvalue",
        ] as $value) {
            $this->assertSame("'".$value, SpreadsheetText::safe($value));
        }
    }

    public function test_exact_json_preserves_original_text_and_caller_key_order(): void
    {
        $fields = [
            'project_name' => '=پروژه',
            'keyword_display_text' => "سئو\u{200C}سایت",
            'page_url' => 'https://example.test/مسیر',
        ];

        $json = SpreadsheetText::exactJson($fields);

        $this->assertSame('{"project_name":"=پروژه","keyword_display_text":"سئو‌سایت","page_url":"https://example.test/مسیر"}', $json);
        $this->assertSame($fields, json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
