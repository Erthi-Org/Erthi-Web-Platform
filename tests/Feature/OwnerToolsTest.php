<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

final class OwnerToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_diagnostics_are_owner_only_and_follow_enabled_locales(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->get('/admin/site-health')->assertForbidden();
        $this->get('/admin/seo-audit')->assertForbidden();

        config(['app.url' => 'https://erthi.example', 'erthi.indexing' => true, 'app.debug' => true]);
        DB::table('site_settings')->updateOrInsert(['key' => 'english_enabled'], ['value' => '0']);
        DB::table('site_settings')->updateOrInsert(['key' => 'description_ar'], ['value' => str_repeat('أ', 161)]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/admin/site-health')->assertOk()->assertInertia(fn (Page $page) => $page
            ->where('data.تصحيح الأخطاء معطّل', false)->where('data.عنوان الموقع يستخدم HTTPS', true));
        $this->get('/admin/seo-audit')->assertOk()->assertInertia(fn (Page $page) => $page
            ->where('data.indexing', true)->has('data.pages', count(config('erthi.pages')))
            ->where('data.pages.0.locale', 'ar')->where('data.pages.0.url', 'https://erthi.example/ar')
            ->where('data.pages.0.description_shortened', true));
    }
}
