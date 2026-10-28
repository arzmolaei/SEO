<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $crawl_run_id
 * @property int|null $page_id
 * @property string $url
 * @property string $normalized_url
 * @property string $url_hash
 * @property int $depth
 * @property string $source
 * @property string $state
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property int|null $status_code
 * @property string|null $final_url
 * @property int $redirect_count
 * @property string|null $content_type
 * @property int|null $response_ms
 * @property int|null $bytes
 * @property string|null $title
 * @property string|null $meta_description
 * @property string|null $robots
 * @property string|null $canonical
 * @property list<string>|null $h1
 * @property list<string>|null $h2
 * @property list<array{lang: string, href: string}>|null $hreflang
 * @property list<string>|null $schema_types
 * @property int|null $word_count
 * @property int|null $images
 * @property int|null $images_missing_alt
 * @property int|null $internal_links
 * @property int|null $external_links
 * @property bool|null $indexable
 * @property string|null $error_code
 * @property CarbonImmutable|null $fetched_at
 */
#[Fillable([
    'crawl_run_id', 'page_id', 'url', 'normalized_url', 'url_hash', 'depth', 'source', 'state', 'attempts',
    'next_attempt_at', 'status_code', 'final_url', 'redirect_count', 'content_type', 'response_ms', 'bytes',
    'title', 'meta_description', 'robots', 'canonical', 'h1', 'h2', 'hreflang', 'schema_types', 'word_count',
    'images', 'images_missing_alt', 'internal_links', 'external_links', 'indexable', 'error_code', 'fetched_at',
])]
class CrawlUrl extends Model
{
    /** @return BelongsTo<CrawlRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'crawl_run_id');
    }

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'attempts' => 'integer',
            'status_code' => 'integer',
            'redirect_count' => 'integer',
            'response_ms' => 'integer',
            'bytes' => 'integer',
            'h1' => 'array',
            'h2' => 'array',
            'hreflang' => 'array',
            'schema_types' => 'array',
            'word_count' => 'integer',
            'images' => 'integer',
            'images_missing_alt' => 'integer',
            'internal_links' => 'integer',
            'external_links' => 'integer',
            'indexable' => 'boolean',
            'next_attempt_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
