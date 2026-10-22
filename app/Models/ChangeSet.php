<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $project_id
 * @property int|null $actor_id
 * @property string $action
 * @property list<array{keyword_id: int, before: string, after: string}> $changes
 * @property Carbon|null $undone_at
 * @property int|null $undone_by_id
 */
class ChangeSet extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'actor_id',
        'action',
        'changes',
        'undone_at',
        'undone_by_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'undone_at' => 'datetime',
        ];
    }
}
