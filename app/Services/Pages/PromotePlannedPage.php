<?php

namespace App\Services\Pages;

use App\Domain\Pages\NormalizePageUrl;
use App\Domain\Pages\PageKind;
use App\Events\Pages\PagePromoted;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PromotePlannedPage
{
    public function __construct(private PageOperations $operations, private NormalizePageUrl $normalizer) {}

    public function handle(User $actor, Project $project, Page $page, string $url): Page
    {
        return DB::transaction(function () use ($actor, $project, $page, $url): Page {
            $this->operations->authorize($actor, $project);
            if ($page->project_id !== $project->id) {
                throw new AuthorizationException;
            }
            $page = Page::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($page->id);
            $normalized = $this->normalizer->normalize($url);
            $hash = hash('sha256', $normalized);
            if ($page->kind === PageKind::Existing) {
                if ($page->normalized_url === $normalized) {
                    return $page;
                } throw new ConflictHttpException('Only planned pages can be promoted.');
            }
            if (Page::query()->where('project_id', $project->id)->where('url_hash', $hash)->where('id', '!=', $page->id)->exists()) {
                throw new ConflictHttpException('Another page already has this URL.');
            }
            $before = $page->getAttributes();
            $page->update(['kind' => PageKind::Existing, 'url' => $url, 'normalized_url' => $normalized, 'url_hash' => $hash]);
            event(new PagePromoted($actor, $project, $page));
            $this->operations->record($actor, $project, $page, $before, 'pages.promoted');

            return $page;
        });
    }
}
