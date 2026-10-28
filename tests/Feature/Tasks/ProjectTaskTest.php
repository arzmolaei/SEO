<?php

namespace Tests\Feature\Tasks;

use App\Enums\WorkspaceRole;
use App\Models\ChangeSet;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00:00');
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

    private function page(string $title, ?Project $project = null): int
    {
        return DB::table('pages')->insertGetId([
            'project_id' => ($project ?? $this->project)->id, 'kind' => 'planned', 'title' => $title,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cluster(string $name): int
    {
        $keyword = DB::table('keywords')->insertGetId([
            'project_id' => $this->project->id, 'display_text' => $name, 'normalized_text' => $name,
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('keyword_clusters')->insertGetId([
            'project_id' => $this->project->id, 'primary_keyword_id' => $keyword, 'name' => $name,
            'review_state' => 'reviewed', 'lifecycle_state' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function add(array $data): ProjectTask
    {
        $this->actingAs($this->owner)->post(route('projects.tasks.store', $this->project), $data)->assertSessionHasNoErrors();

        return ProjectTask::query()->latest('id')->firstOrFail();
    }

    public function test_tasks_link_to_a_page_or_cluster_and_list_open_work_by_due_date(): void
    {
        $page = $this->page('صفحه کفش');
        $cluster = $this->cluster('خرید کفش');
        $later = $this->add(['title' => 'نوشتن متن', 'due_on' => '2026-11-20', 'page_id' => $page]);
        $late = $this->add(['title' => 'اصلاح عنوان', 'due_on' => '2026-10-30', 'keyword_cluster_id' => $cluster]);
        $undated = $this->add(['title' => 'ایده']);
        $done = $this->add(['title' => 'تمام‌شده']);
        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$this->project, $done]), ['status' => 'done']);

        $this->actingAs($this->owner)->get(route('projects.tasks.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->component('projects/tasks')
                ->where('tasks.data.0.id', $late->id)
                ->where('tasks.data.0.overdue', true)
                ->where('tasks.data.0.cluster', ['id' => $cluster, 'label' => 'خرید کفش'])
                ->where('tasks.data.1.id', $later->id)
                ->where('tasks.data.1.page', ['id' => $page, 'label' => 'صفحه کفش'])
                ->where('tasks.data.2.id', $undated->id)
                ->has('tasks.data', 3)
                ->where('counts', ['open' => 3, 'overdue' => 1, 'done' => 1]));

        $this->actingAs($this->owner)->get(route('projects.tasks.index', ['project' => $this->project, 'view' => 'done']))
            ->assertInertia(fn (Assert $view) => $view->has('tasks.data', 1)->where('tasks.data.0.id', $done->id));
        $this->actingAs($this->owner)->get(route('projects.tasks.index', ['project' => $this->project, 'view' => 'overdue']))
            ->assertInertia(fn (Assert $view) => $view->has('tasks.data', 1));
        $this->actingAs($this->owner)->get(route('projects.tasks.index', ['project' => $this->project, 'page_id' => $page]))
            ->assertInertia(fn (Assert $view) => $view->has('tasks.data', 1)
                ->where('context.page', ['id' => $page, 'label' => 'صفحه کفش'])->where('counts.open', 1));
        $this->actingAs($this->owner)->get(route('projects.tasks.index', ['project' => $this->project, 'q' => 'عنوان']))
            ->assertInertia(fn (Assert $view) => $view->has('tasks.data', 1));
    }

    public function test_completing_records_when_and_reopening_clears_it_with_audit(): void
    {
        $task = $this->add(['title' => 'نوشتن متا']);
        $this->assertSame(1, ChangeSet::query()->where('action', 'task.created')->count());

        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$this->project, $task]), ['status' => 'done']);
        $this->assertSame('2026-11-02T10:00:00+00:00', $task->refresh()->completed_at?->toIso8601String());
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$this->project, $task]), ['status' => 'done', 'notes' => 'انجام شد']);
        $this->assertSame('2026-11-02', $task->refresh()->completed_at?->toDateString(), 'editing a done task keeps its completion time');
        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$this->project, $task]), ['status' => 'doing']);
        $this->assertNull($task->refresh()->completed_at);
        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$this->project, $task]), ['status' => 'doing']);

        $this->assertSame(3, ChangeSet::query()->where('action', 'task.updated')->count(), 'an unchanged save is not recorded');
    }

    public function test_invalid_input_and_links_to_other_projects_are_rejected(): void
    {
        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $foreignPage = $this->page('بیگانه', $other);

        $this->actingAs($this->owner)->post(route('projects.tasks.store', $this->project), ['title' => '', 'due_on' => '2/11/2026', 'status' => 'later'])
            ->assertSessionHasErrors(['title', 'due_on', 'status']);
        $this->actingAs($this->owner)->post(route('projects.tasks.store', $this->project), ['title' => 'x', 'page_id' => $foreignPage])
            ->assertSessionHasErrors('page_id');
        $this->assertSame(0, ProjectTask::query()->count());
    }

    public function test_deleting_keeps_a_recoverable_record_and_removed_links_keep_the_task(): void
    {
        $page = $this->page('صفحه');
        $task = $this->add(['title' => 'حذفی']);
        $kept = $this->add(['title' => 'ماندگار', 'page_id' => $page]);

        $this->actingAs($this->owner)->delete(route('projects.tasks.destroy', [$this->project, $task]))->assertRedirect();
        $this->assertSame('حذفی', ChangeSet::query()->where('action', 'task.deleted')->sole()->changes[0]['before']['title']);

        DB::table('pages')->where('id', $page)->delete();
        $this->assertNull($kept->refresh()->page_id);
        $this->assertSame(1, ProjectTask::query()->count());
    }

    public function test_viewers_read_only_and_other_projects_are_isolated(): void
    {
        $task = $this->add(['title' => 'کار']);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->actingAs($viewer)->get(route('projects.tasks.index', $this->project))
            ->assertOk()->assertInertia(fn (Assert $view) => $view->where('canEdit', false)->has('tasks.data', 1));
        $this->actingAs($viewer)->post(route('projects.tasks.store', $this->project), ['title' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('projects.tasks.update', [$this->project, $task]), ['status' => 'done'])->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.tasks.index', $this->project))->assertForbidden();

        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $this->actingAs($this->owner)->patch(route('projects.tasks.update', [$other, $task]), ['status' => 'done'])->assertNotFound();
        $this->assertSame('todo', $task->refresh()->status);
    }
}
