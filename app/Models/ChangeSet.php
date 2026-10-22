<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChangeSet extends Model
{
    use HasFactory;

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
