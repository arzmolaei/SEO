<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One observed difference between a crawl and its comparable baseline crawl.
 *
 * @property int $id
 * @property int $crawl_run_id
 * @property int $baseline_run_id
 * @property string $url_hash
 * @property string $normalized_url
 * @property string $change_type
 * @property array{value: mixed}|null $before
 * @property array{value: mixed}|null $after
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['crawl_run_id', 'baseline_run_id', 'url_hash', 'normalized_url', 'change_type', 'before', 'after', 'created_at'])]
class CrawlChange extends Model
{
    public const TYPES = ['added', 'removed', 'status', 'title', 'meta_description', 'canonical', 'indexability', 'internal_links'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
