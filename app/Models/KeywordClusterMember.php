<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $keyword_cluster_id
 * @property int $keyword_id
 */
class KeywordClusterMember extends Model
{
    protected $fillable = ['project_id', 'keyword_cluster_id', 'keyword_id'];

    protected static function booted(): void
    {
        static::deleting(fn (self $member) => $member->assertNotPrimary());
        static::updating(function (self $member): void {
            if ($member->isDirty(['project_id', 'keyword_cluster_id', 'keyword_id'])) {
                $member->assertNotPrimary();
            }
        });
    }

    private function assertNotPrimary(): void
    {
        if (KeywordCluster::query()->whereKey($this->getOriginal('keyword_cluster_id'))
            ->where('primary_keyword_id', $this->getOriginal('keyword_id'))->exists()) {
            throw new DomainException('Replace the primary keyword before removing or moving its membership.');
        }
    }

    /** @return BelongsTo<KeywordCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }

    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
