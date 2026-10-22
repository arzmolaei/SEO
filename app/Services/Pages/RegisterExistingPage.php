<?php

namespace App\Services\Pages;

use App\Domain\Pages\NormalizePageUrl;
use App\Domain\Pages\PageKind;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RegisterExistingPage
{
    public function __construct(private PageOperations $operations, private NormalizePageUrl $normalizer) {}

    public function handle(User $actor, Project $project, string $url, ?string $title = null): Page
    {
        return DB::transaction(function () use ($actor, $project, $url, $title): Page {
            $this->operations->authorize($actor, $project);
            $normalized = $this->normalizer->normalize($url);
            $hash = hash('sha256', $normalized);
            $existing = Page::query()->where('project_id', $project->id)->where('url_hash', $hash)->first();
            if ($existing instanceof Page) {
                if ($existing->normalized_url !== $normalized) {
                    throw new ConflictHttpException('URL identity collision.');
                }

return $existing;
            }
            $page = Page::query()->create(['project_id' => $project->id, 'created_by_id' => $actor->id, 'kind' => PageKind::Existing, 'title' => $this->operations->title($title), 'url' => $url, 'normalized_url' => $normalized, 'url_hash' => $hash]);
            $this->operations->record($actor, $project, $page, null, 'pages.created');

            return $page;
        });
    }
}
