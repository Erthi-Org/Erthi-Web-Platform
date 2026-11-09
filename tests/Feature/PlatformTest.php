<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_pages_have_language_and_metadata(): void
    {
        $this->get('/')->assertRedirect('/ar');
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach (config('erthi.pages') as $page) {
                $url = '/'.$locale.($page === 'home' ? '' : '/'.$page);
                $this->get($url)->assertOk()
                    ->assertSee('lang="'.$locale.'" dir="'.$direction.'"', false)
                    ->assertSee('property="og:image"', false)
                    ->assertHeader('X-Content-Type-Options', 'nosniff')
                    ->assertInertia(fn (Page $view) => $view->component('Public')->where('page', $page)->where('locale', $locale));
            }
        }
        $this->get('/ar/projects')->assertInertia(fn (Page $view) => $view->has('projects', 2));
        $this->get('/robots.txt')->assertSee('Disallow: /');
        $this->get('/sitemap.xml')->assertNotFound();
        config(['erthi.indexing' => true, 'app.url' => 'https://erthi.org']);
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://erthi.org/en/projects');
        $this->get('/ar')->assertSee('https://erthi.org/ar', false)->assertSee('index, follow');
        $this->get('/fr')->assertNotFound();
    }

    public function test_public_forms_validate_consent_topic_and_honeypot(): void
    {
        $input = ['name' => 'Test Visitor', 'email' => 'test@example.com', 'message' => 'A test message for Erthi.'];
        $this->from('/ar/contact')->post('/ar/contact', $input)->assertSessionHasErrors('consent');
        $this->from('/ar/contribute')->post('/ar/contribute', $input + ['consent' => true])->assertSessionHasErrors('topic');
        $this->from('/ar/contact')->post('/ar/contact', $input + ['consent' => true, 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->assertDatabaseCount('submissions', 0);

        $this->from('/en/contact')->post('/en/contact', $input + ['consent' => true, 'topic' => ''])->assertRedirect('/en/contact')->assertSessionHas('notice');
        $this->from('/ar/contribute')->post('/ar/contribute', $input + ['consent' => true, 'topic' => 'language'])->assertRedirect('/ar/contribute');
        $this->post('/ar/contact', $input + ['consent' => true])->assertStatus(429);
        $this->assertDatabaseHas('submissions', ['kind' => 'contact', 'email' => 'test@example.com']);
        $this->assertDatabaseHas('submissions', ['kind' => 'contribute', 'topic' => 'language']);
    }

    public function test_admin_permissions_and_private_headers(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->from('/login')->post('/login', ['email' => $viewer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->actingAs($viewer)->get('/admin')->assertForbidden();

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/users')->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        foreach (AdminSite::Sections as $section) {
            $this->get('/admin/'.$section)->assertOk()->assertInertia(fn (Page $view) => $view->component('Admin')->where('section', $section));
        }
        $this->post('/admin/users/'.$owner->id, ['role' => 'viewer'])->assertNotFound();
        $this->post('/admin/users/'.$editor->id, ['role' => 'viewer'])->assertRedirect();
        $this->actingAs($editor->fresh())->get('/admin')->assertForbidden();
    }

    public function test_admin_login_and_logout(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($owner);
        $this->post('/logout')->assertRedirect('/ar');
        $this->assertGuest();
    }

    public function test_admin_projects_content_languages_and_seo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $project = [
            'title_ar' => 'عنوان', 'title_en' => 'Test project', 'summary_ar' => 'وصف',
            'summary_en' => 'Test description', 'repository' => 'Erthi-Org/Test',
            'icon' => 'android', 'published' => false,
        ];
        $this->post('/admin/projects', $project)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/en/projects')->assertInertia(fn (Page $view) => $view->has('projects', 2));
        $id = DB::table('projects')->where('repository', $project['repository'])->value('id');
        $this->post('/admin/projects/'.$id, [...$project, 'published' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/en/projects')->assertInertia(fn (Page $view) => $view->has('projects', 3)->where('projects.0.title', 'Test project'));

        $this->post('/admin/content', ['locale' => 'en', 'page' => 'about', 'title' => 'New title', 'body' => 'Updated content.'])->assertRedirect();
        $this->post('/admin/settings/seo', ['title_ar' => 'إرثي', 'title_en' => 'Erthi Initiative', 'description_ar' => 'وصف الموقع', 'description_en' => 'Erthi description'])->assertRedirect();
        $this->get('/en/about')->assertInertia(fn (Page $view) => $view->where('content.about.title', 'New title')->where('seo.title', 'New title | Erthi Initiative'));
        $this->post('/admin/settings/languages', ['english_enabled' => false])->assertRedirect();
        $this->get('/en/about')->assertNotFound();
        $this->get('/ar/about')->assertInertia(fn (Page $view) => $view->where('locales', ['ar']));
    }

    public function test_admin_upload_sanitizes_rasters_and_can_reset_logo(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->post('/admin/media', ['name' => 'Bad', 'image' => $svg])->assertSessionHasErrors('image');
        $this->post('/admin/media', ['name' => 'Logo', 'image' => UploadedFile::fake()->image('logo.png', 48, 48)])->assertRedirect()->assertSessionHasNoErrors();
        $image = DB::table('media')->first();
        Storage::disk('local')->assertExists($image->file);
        $this->get('/media/'.$image->id)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->post('/admin/settings/identity', ['logo_id' => $image->id])->assertRedirect();
        $this->get('/ar')->assertInertia(fn (Page $view) => $view->where('logo', '/media/'.$image->id));
        $this->post('/admin/settings/identity', ['logo_id' => ''])->assertRedirect();
        $this->get('/ar')->assertInertia(fn (Page $view) => $view->where('logo', null));
    }

    public function test_inbox_review_and_deletion_are_scoped_to_submission_kind(): void
    {
        $this->post('/ar/contact', ['name' => 'Test Visitor', 'email' => 'test@example.com', 'message' => 'A test message.', 'consent' => true]);
        $id = DB::table('submissions')->value('id');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->post('/admin/submissions/contribute/'.$id)->assertNotFound();
        $this->post('/admin/submissions/contact/'.$id)->assertRedirect();
        $this->assertNotNull(DB::table('submissions')->where('id', $id)->value('reviewed_at'));
        $this->delete('/admin/submissions/contact/'.$id)->assertRedirect();
        $this->assertDatabaseCount('submissions', 0);
    }
}
