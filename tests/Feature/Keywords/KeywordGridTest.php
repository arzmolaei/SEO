<?php

namespace Tests\Feature\Keywords;

use App\Models\Project;
use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeywordGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_is_server_paginated_and_never_returns_the_entire_collection(): void
    {
        [$owner, $project] = $this->projectWithKeywords();

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/keywords?per_page=2&sort=keyword&direction=asc");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/keywords')
            ->has('keywords.data', 2)
            ->where('keywords.total', 3)
            ->where('keywords.per_page', 2)
            ->where('filters.sort', 'keyword')
            ->where('filters.direction', 'asc')
        );
    }

    public function test_grid_searches_keyword_text_on_the_server(): void
    {
        [$owner, $project] = $this->projectWithKeywords();

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/keywords?q=طراحی");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/keywords')
            ->has('keywords.data', 1)
            ->where('keywords.data.0.display_text', 'طراحی سایت')
        );
    }

    public function test_grid_filters_by_source_and_numeric_volume(): void
    {
        [$owner, $project] = $this->projectWithKeywords();

        $response = $this->actingAs($owner)->get(
            "/projects/{$project->id}/keywords?source=semrush&volume_operator=gte&volume=800",
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/keywords')
            ->has('keywords.data', 1)
            ->where('keywords.data.0.display_text', 'خرید لپ تاپ')
            ->where('keywords.data.0.latest_volume_source', 'semrush')
        );
    }

    public function test_grid_can_sort_by_latest_volume(): void
    {
        [$owner, $project] = $this->projectWithKeywords();

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/keywords?sort=volume&direction=desc");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/keywords')
            ->where('keywords.data.0.display_text', 'طراحی سایت')
            ->where('keywords.data.0.latest_volume', '2000.0000')
        );
    }

    public function test_grid_rejects_unknown_filter_operators(): void
    {
        [$owner, $project] = $this->projectWithKeywords();

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?volume_operator=contains&volume=100")
            ->assertUnprocessable();
    }

    public function test_outsider_cannot_query_keyword_grid_by_guessing_project_id(): void
    {
        [$owner, $project] = $this->projectWithKeywords();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get("/projects/{$project->id}/keywords")
            ->assertForbidden();
    }

    /** @return array{User, Project} */
    private function projectWithKeywords(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $kwfinder = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'kwfinder',
            originalFilename: 'kwfinder.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: [
                ['Keyword' => 'سئو سایت', 'Volume' => '1200'],
                ['Keyword' => 'طراحی سایت', 'Volume' => '2000'],
            ],
        );
        app(CommitKeywordImportBatch::class)->handle($owner, $kwfinder);

        $semrush = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'semrush',
            originalFilename: 'semrush.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: [
                ['Keyword' => 'خرید لپ تاپ', 'Volume' => '900'],
            ],
        );
        app(CommitKeywordImportBatch::class)->handle($owner, $semrush);

        return [$owner, $project];
    }
}
