<?php

namespace Tests\Feature\Links;

use App\Enums\WorkspaceRole;
use App\Models\ChangeSet;
use App\Models\InternalLinkDecision;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InternalLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'فضای من']);
        $this->owner = $this->member(WorkspaceRole::Owner);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'فروشگاه']);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param  list<string>  $keywords  first one is the primary */
    private function mappedPage(string $title, array $keywords, ?Project $project = null): int
    {
        $project ??= $this->project;
        $ids = [];
        foreach ($keywords as $text) {
            $ids[] = DB::table('keywords')->insertGetId([
                'project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text.'#'.$project->id,
                'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $cluster = DB::table('keyword_clusters')->insertGetId([
            'project_id' => $project->id, 'primary_keyword_id' => $ids[0], 'name' => $title,
            'review_state' => 'reviewed', 'lifecycle_state' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($ids as $id) {
            DB::table('keyword_cluster_members')->insert(['project_id' => $project->id, 'keyword_cluster_id' => $cluster, 'keyword_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $page = DB::table('pages')->insertGetId(['project_id' => $project->id, 'kind' => 'planned', 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cluster_targets')->insert(['project_id' => $project->id, 'keyword_cluster_id' => $cluster, 'page_id' => $page, 'type' => 'planned_page', 'created_at' => now(), 'updated_at' => now()]);

        return $page;
    }

    public function test_mapped_pages_on_a_shared_topic_are_suggested_and_decisions_move_them_between_views(): void
    {
        $sport = $this->mappedPage('کفش ورزشی', ['کفش ورزشی', 'کفش ورزشی مردانه']);
        $running = $this->mappedPage('کفش دویدن', ['کفش دویدن', 'کفش ورزشی دویدن']);
        $this->mappedPage('لپ تاپ', ['لپ تاپ']);

        $this->actingAs($this->owner)->get(route('projects.internal-links.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->component('projects/internal-links')
                ->has('links.data', 2)
                ->where('counts', ['open' => 2, 'done' => 0, 'dismissed' => 0])
                ->where('mapped', 3));

        $this->actingAs($this->owner)->post(route('projects.internal-links.decide', $this->project), [
            'source_page_id' => $sport, 'target_page_id' => $running, 'status' => 'done', 'anchor' => 'کفش دویدن',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('projects.internal-links.decide', $this->project), [
            'source_page_id' => $running, 'target_page_id' => $sport, 'status' => 'dismissed',
        ]);

        $this->actingAs($this->owner)->get(route('projects.internal-links.index', ['project' => $this->project, 'view' => 'done']))
            ->assertInertia(fn (Assert $view) => $view->has('links.data', 1)
                ->where('links.data.0.source.id', $sport)->where('links.data.0.target.title', 'کفش دویدن')
                ->where('links.data.0.anchor', 'کفش دویدن')
                ->where('counts', ['open' => 0, 'done' => 1, 'dismissed' => 1]));

        // Reopening removes the decision and the suggestion is open again.
        $this->actingAs($this->owner)->post(route('projects.internal-links.decide', $this->project), [
            'source_page_id' => $running, 'target_page_id' => $sport, 'status' => null,
        ]);
        $this->assertSame(1, InternalLinkDecision::query()->count());
        $this->assertSame(3, ChangeSet::query()->where('action', 'internal_link.decided')->count());

        $this->actingAs($this->owner)->get(route('projects.internal-links.index', ['project' => $this->project, 'page_id' => $running]))
            ->assertInertia(fn (Assert $view) => $view->has('links.data', 1)
                ->where('links.data.0.source.id', $running)->where('focus.id', $running));
    }

    public function test_decisions_require_two_distinct_pages_of_the_same_project(): void
    {
        $page = $this->mappedPage('کفش', ['کفش']);
        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $foreign = $this->mappedPage('بیگانه', ['بیگانه'], $other);

        foreach ([[$page, $page], [$page, $foreign]] as [$source, $target]) {
            $this->actingAs($this->owner)->post(route('projects.internal-links.decide', $this->project), [
                'source_page_id' => $source, 'target_page_id' => $target, 'status' => 'done',
            ])->assertSessionHasErrors('link');
        }
        $this->actingAs($this->owner)->post(route('projects.internal-links.decide', $this->project), [
            'source_page_id' => $page, 'target_page_id' => $page, 'status' => 'maybe',
        ])->assertSessionHasErrors('status');
        $this->assertSame(0, InternalLinkDecision::query()->count());
    }

    public function test_viewers_read_only_and_outsiders_are_refused(): void
    {
        $a = $this->mappedPage('کفش ورزشی', ['کفش ورزشی']);
        $b = $this->mappedPage('کفش ورزشی زنانه', ['کفش ورزشی زنانه']);
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($viewer)->get(route('projects.internal-links.index', $this->project))
            ->assertOk()->assertInertia(fn (Assert $view) => $view->where('canEdit', false));
        $this->actingAs($viewer)->post(route('projects.internal-links.decide', $this->project), [
            'source_page_id' => $a, 'target_page_id' => $b, 'status' => 'done',
        ])->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.internal-links.index', $this->project))->assertForbidden();
    }
}
