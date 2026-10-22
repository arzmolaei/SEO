<?php

namespace App\Services\Pages;

use App\Domain\Pages\PageKind;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePlannedPage
{
    public function __construct(private PageOperations $operations) {}

    public function handle(User $actor, Project $project, string $title): Page
    {
        return DB::transaction(function () use ($actor, $project, $title): Page {
            $this->operations->authorize($actor, $project);
            $page = Page::query()->create(['project_id' => $project->id, 'created_by_id' => $actor->id, 'title' => $this->operations->title($title, true), 'kind' => PageKind::Planned]);
            $this->operations->record($actor, $project, $page, null, 'pages.created');

            return $page;
        });
    }
}
