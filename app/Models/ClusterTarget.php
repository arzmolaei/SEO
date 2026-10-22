<?php

namespace App\Models;

use App\Domain\Keywords\ClusterTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $keyword_cluster_id
 * @property int|null $page_id
 * @property int|null $actor_id
 * @property int $revision
 * @property ClusterTargetType $type
 */
class ClusterTarget extends Model
{
    protected $fillable = ['project_id', 'keyword_cluster_id', 'page_id', 'actor_id', 'revision', 'type'];

    protected $attributes = ['revision' => 1];

    /** @return BelongsTo<KeywordCluster,$this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }

    /** @return BelongsTo<Page,$this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    protected function casts(): array
    {
        return ['type' => ClusterTargetType::class, 'revision' => 'integer'];
    }
}
