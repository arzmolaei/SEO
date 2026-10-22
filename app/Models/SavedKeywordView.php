<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property int $project_id
 * @property string $name
 * @property int $schema_version
 * @property array{query: array<string, mixed>, columns: list<string>} $state
 * @property bool $is_default
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'project_id',
    'name',
    'schema_version',
    'state',
    'is_default',
])]
class SavedKeywordView extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'schema_version' => 'integer',
            'is_default' => 'boolean',
        ];
    }
}
