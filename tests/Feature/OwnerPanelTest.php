<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class OwnerPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owners_can_check_runtime_and_local_public_metadata(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->get('/admin/system-health')->assertForbidden();
        $this->get('/admin/seo-audit')->assertForbidden();

        config(['app.url' => 'https://erthi.org', 'erthi.indexing' => false, 'app.debug' => false]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/admin/system-health')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Page $page) => $page->where('data.https', true)->where('data.debug', true));
        $this->get('/admin/seo-audit')->assertOk()->assertInertia(fn (Page $page) => $page
            ->where('data.indexing', false)->has('data.pages', 14)
            ->where('data.pages.0.url', 'https://erthi.org/ar')->where('data.pages.0.checks.https', true));
        $this->post('/admin/settings/languages', ['english_enabled' => false])->assertSessionHasNoErrors();
        $this->get('/admin/seo-audit')->assertInertia(fn (Page $page) => $page->has('data.pages', 7));
        Http::assertNothingSent();
    }
}
