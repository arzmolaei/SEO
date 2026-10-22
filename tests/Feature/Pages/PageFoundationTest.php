<?php

namespace Tests\Feature\Pages;

use App\Domain\Pages\NormalizePageUrl;
use App\Domain\Pages\PageKind;
use App\Models\User;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Pages\PromotePlannedPage;
use App\Services\Pages\RegisterExistingPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_identity_preserves_path_and_query_semantics(): void
    {
        $normalizer = new NormalizePageUrl;
        $this->assertSame('https://example.com/Path?b=2&a=1', $normalizer->normalize('HTTPS://EXAMPLE.COM:443/Path?b=2&a=1#section'));
        $this->assertSame('http://example.com/', $normalizer->normalize('http://EXAMPLE.com:80'));
        $this->assertNotSame($normalizer->normalize('https://example.com/A'), $normalizer->normalize('https://example.com/a'));
        $this->assertNotSame($normalizer->normalize('http://example.com/'), $normalizer->normalize('https://example.com/'));
    }

    public function test_idn_unicode_matches_its_ascii_form_without_merging_distinct_hosts(): void
    {
        $normalizer = new NormalizePageUrl;
        $this->assertSame($normalizer->normalize('https://xn--fa-hia.de/'), $normalizer->normalize('https://faß.de/'));
        $this->assertNotSame($normalizer->normalize('https://faß.de/'), $normalizer->normalize('https://fass.de/'));
    }

    public function test_unsafe_or_malformed_urls_are_rejected_without_network(): void
    {
        foreach (['javascript:alert(1)', 'file:///tmp/a', 'https://user:secret@example.com/a', "https://example.com/a\n", 'https://example.com/%xx', 'https://', 'https://example.com:99999/'] as $url) {
            try {
                (new NormalizePageUrl)->normalize($url);
                $this->fail('Invalid URL accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_existing_registration_is_project_unique_idempotent_and_preserves_original(): void
    {
        [$actor,$project] = $this->fixture();
        $register = app(RegisterExistingPage::class);
        $first = $register->handle($actor, $project, 'HTTPS://EXAMPLE.COM:443/a#x', 'عنوان اصلی');
        $same = $register->handle($actor, $project, 'https://example.com/a', 'Different');
        $this->assertSame($first->id, $same->id);
        $this->assertSame('HTTPS://EXAMPLE.COM:443/a#x', $same->url);
        $this->assertSame('عنوان اصلی', $same->title);
        $this->assertSame(PageKind::Existing, $same->kind);
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseHas('activity_events', ['event' => 'pages.created', 'actor_id' => $actor->id]);
    }

    public function test_planned_pages_promote_without_identity_loss(): void
    {
        [$actor,$project] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'صفحه برنامه‌ریزی‌شده');
        $this->assertSame(PageKind::Planned, $page->kind);
        $this->assertSame('other', $page->page_type);
        $this->assertNull($page->normalized_url);
        $promoted = app(PromotePlannedPage::class)->handle($actor, $project, $page, 'https://example.com/planned');
        $this->assertSame($page->id, $promoted->id);
        $this->assertSame(PageKind::Existing, $promoted->kind);
        $this->assertSame('صفحه برنامه‌ریزی‌شده', $promoted->title);
        $this->assertDatabaseHas('activity_events', ['event' => 'pages.promoted']);
    }

    public function test_promotion_collision_leaves_planned_page_unchanged(): void
    {
        [$actor,$project] = $this->fixture();
        app(RegisterExistingPage::class)->handle($actor, $project, 'https://example.com/a');
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        try {
            app(PromotePlannedPage::class)->handle($actor, $project, $page, 'https://example.com/a');
            $this->fail('Collision accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame(PageKind::Planned, $page->fresh()->kind);
            $this->assertNull($page->fresh()->url);
        }
    }

    public function test_foreign_page_and_unauthorized_actor_are_rejected(): void
    {
        [$actor,$project] = $this->fixture();
        [$foreignActor,$foreignProject] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($foreignActor, $foreignProject, 'Foreign');
        $this->expectException(AuthorizationException::class);
        app(PromotePlannedPage::class)->handle($actor, $project, $page, 'https://example.com/foreign');
    }

    public function test_outsider_cannot_create_and_equivalent_urls_in_other_projects_do_not_merge(): void
    {
        [$actor,$project] = $this->fixture();
        [$other,$otherProject] = $this->fixture();
        $a = app(RegisterExistingPage::class)->handle($actor, $project, 'https://example.com/a');
        $b = app(RegisterExistingPage::class)->handle($other, $otherProject, 'https://example.com/a');
        $this->assertNotSame($a->id, $b->id);
        $this->expectException(AuthorizationException::class);
        app(CreatePlannedPage::class)->handle($other, $project, 'Forbidden');
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');

        return [$actor, app(CreateProject::class)->handle($actor, $workspace, 'example.com')];
    }
}
