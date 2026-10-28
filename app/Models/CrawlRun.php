<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by_id
 * @property string $mode
 * @property string $start_url
 * @property string $normalized_start_url
 * @property list<string> $allowed_hosts
 * @property array{include: list<string>, exclude: list<string>, max_depth: int, max_urls: int, delay_ms: int, include_www: bool, sitemaps_read?: bool} $settings
 * @property string $status
 * @property int $discovered_count
 * @property int $fetched_count
 * @property int $failed_count
 * @property int $blocked_count
 * @property string|null $failure_code
 * @property CarbonImmutable|null $pause_requested_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'project_id', 'created_by_id', 'mode', 'start_url', 'normalized_start_url', 'allowed_hosts', 'settings',
    'status', 'discovered_count', 'fetched_count', 'failed_count', 'blocked_count', 'failure_code',
    'pause_requested_at', 'cancel_requested_at', 'started_at', 'finished_at',
])]
class CrawlRun extends Model
{
    public const ACTIVE_STATUSES = ['queued', 'running', 'paused'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<CrawlUrl, $this> */
    public function urls(): HasMany
    {
        return $this->hasMany(CrawlUrl::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    protected function casts(): array
    {
        return [
            'allowed_hosts' => 'array',
            'settings' => 'array',
            'discovered_count' => 'integer',
            'fetched_count' => 'integer',
            'failed_count' => 'integer',
            'blocked_count' => 'integer',
            'pause_requested_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
