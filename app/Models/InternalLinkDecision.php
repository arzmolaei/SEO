<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property int $source_page_id
 * @property int $target_page_id
 * @property string $status
 * @property string|null $anchor
 * @property int|null $decided_by_id
 */
#[Fillable(['project_id', 'source_page_id', 'target_page_id', 'status', 'anchor', 'decided_by_id'])]
class InternalLinkDecision extends Model
{
    /** done: the owner added the link; dismissed: not useful. */
    public const STATUSES = ['done', 'dismissed'];
}
