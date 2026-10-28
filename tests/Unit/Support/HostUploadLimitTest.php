<?php

namespace Tests\Unit\Support;

use App\Support\HostUploadLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostUploadLimitTest extends TestCase
{
    /** @return array<string, array{string, string, ?int}> */
    public static function limits(): array
    {
        return [
            'php defaults' => ['2M', '8M', 2 * 1024 * 1024],
            'post smaller' => ['256M', '64M', 64 * 1024 * 1024],
            'gigabytes' => ['1G', '2G', 1024 * 1024 * 1024],
            'kilobytes and bytes' => ['512K', '1000000', 512 * 1024],
            'unlimited post' => ['16M', '0', 16 * 1024 * 1024],
            'both unlimited' => ['0', '0', null],
        ];
    }

    #[DataProvider('limits')]
    public function test_effective_limit_is_the_smaller_of_upload_and_post_limits(string $upload, string $post, ?int $expected): void
    {
        $this->assertSame($expected, HostUploadLimit::fromIni($upload, $post));
    }
}
