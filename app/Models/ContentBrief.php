<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $keyword_cluster_id
 * @property int|null $page_id
 * @property int|null $created_by_id
 * @property string $title
 * @property string $primary_keyword
 * @property list<string> $secondary_keywords
 * @property string $intent
 * @property list<string> $headings
 * @property string|null $notes
 * @property string $status
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $published_on
 */
#[Fillable([
    'project_id', 'keyword_cluster_id', 'page_id', 'created_by_id', 'title', 'primary_keyword',
    'secondary_keywords', 'intent', 'headings', 'notes', 'status', 'due_on', 'published_on',
])]
class ContentBrief extends Model
{
    public const STATUSES = ['draft', 'review', 'published'];

    protected $attributes = ['status' => 'draft', 'intent' => 'unknown'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'headings' => 'array',
            'due_on' => 'immutable_date',
            'published_on' => 'immutable_date',
        ];
    }
}
