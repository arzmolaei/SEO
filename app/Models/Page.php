<?php

namespace App\Models;

use App\Domain\Pages\PageKind;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by_id
 * @property string|null $title
 * @property PageKind $kind
 * @property string $page_type
 * @property string|null $url
 * @property string|null $normalized_url
 * @property string|null $url_hash
 * @property string|null $seo_title
 * @property string|null $meta_description
 * @property string|null $h1
 * @property string $on_page_status
 * @property string|null $on_page_notes
 */
class Page extends Model
{
    protected $attributes = ['page_type' => 'other', 'on_page_status' => 'todo'];

    protected $fillable = ['project_id', 'created_by_id', 'title', 'kind', 'page_type', 'url', 'normalized_url', 'url_hash', 'seo_title', 'meta_description', 'h1', 'on_page_status', 'on_page_notes'];

    protected static function booted(): void
    {
        static::updating(function (self $page): void {
            if ($page->isDirty('project_id')) {
                throw new DomainException('Pages cannot move across projects.');
            }
        });
    }

    /** @return BelongsTo<Project,$this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['kind' => PageKind::class];
    }
}
