<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $search_console_import_id
 * @property int $project_id
 * @property string $key_text
 * @property string $normalized_key
 * @property string $key_hash
 * @property int $clicks
 * @property int $impressions
 * @property string $position
 */
#[Fillable(['search_console_import_id', 'project_id', 'key_text', 'normalized_key', 'key_hash', 'clicks', 'impressions', 'position'])]
class SearchConsoleRow extends Model
{
    public $timestamps = false;
}
