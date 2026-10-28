<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $page_id
 * @property int|null $keyword_cluster_id
 * @property int|null $created_by_id
 * @property string $title
 * @property string|null $notes
 * @property string $status
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable(['project_id', 'page_id', 'keyword_cluster_id', 'created_by_id', 'title', 'notes', 'status', 'due_on', 'completed_at'])]
class ProjectTask extends Model
{
    public const STATUSES = ['todo', 'doing', 'done'];

    protected $attributes = ['status' => 'todo'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
