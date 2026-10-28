<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlResolverTest extends TestCase
{
    /** @return array<string, array{string, string|null}> RFC 3986 §5.4 plus crawler rules */
    public static function references(): array
    {
        return [
            'g' => ['g', 'http://a/b/c/g'], './g' => ['./g', 'http://a/b/c/g'], 'g/' => ['g/', 'http://a/b/c/g/'],
            '/g' => ['/g', 'http://a/g'], '//g' => ['//g', 'http://g/'], '?y' => ['?y', 'http://a/b/c/d;p?y'],
            'g?y' => ['g?y', 'http://a/b/c/g?y'], 'g#s' => ['g#s', 'http://a/b/c/g'], '.' => ['.', 'http://a/b/c/'],
            '..' => ['..', 'http://a/b/'], '../g' => ['../g', 'http://a/b/g'], '../..' => ['../..', 'http://a/'],
            '../../../g' => ['../../../g', 'http://a/g'], '/./g' => ['/./g', 'http://a/g'], 'g.' => ['g.', 'http://a/b/c/g.'],
            'g;x=1/../y' => ['g;x=1/../y', 'http://a/b/c/y'], 'persian path' => ['/صفحه', 'http://a/صفحه'],
            'fragment only' => ['#s', null], 'mailto' => ['mailto:x@y', null], 'javascript' => ['javascript:alert(1)', null],
            'absolute with dots' => ['https://b/x/../y', 'https://b/y'],
        ];
    }

    #[DataProvider('references')]
    public function test_references_resolve_against_the_page_url(string $reference, ?string $expected): void
    {
        $this->assertSame($expected, UrlResolver::resolve('http://a/b/c/d;p?q', $reference));
    }
}
