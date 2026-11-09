<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

final class ReleaseChannelTest extends TestCase
{
    use RefreshDatabase;

    private const Endpoint = 'https://api.github.com/repos/Erthi-Org/Erthi-Web-Platform/releases/tags/erthi-update-3';

    private const Download = 'https://github.com/Erthi-Org/Erthi-Web-Platform/releases/download/erthi-update-3/erthi-update-3.zip';

    public function test_owner_can_find_the_next_release_without_installing_it(): void
    {
        $storage = sys_get_temp_dir().'/erthi-release-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($storage);
        $directory = $storage.'/app/private/site-updates';
        File::ensureDirectoryExists($directory);
        $state = ['key' => '', 'sequence' => 2, 'active_sequence' => 1];
        File::put($directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));

        try {
            Http::preventStrayRequests();
            Http::fake([self::Endpoint => Http::sequence()
                ->push(['tag_name' => 'erthi-update-3', 'draft' => false, 'assets' => [
                    ['name' => 'erthi-update-3.zip', 'state' => 'uploaded', 'browser_download_url' => self::Download],
                ]])
                ->push(['tag_name' => 'erthi-update-3', 'draft' => false, 'assets' => [
                    ['name' => 'erthi-update-3.zip', 'state' => 'uploaded', 'browser_download_url' => 'https://elsewhere.example/update.zip'],
                ]])
                ->pushStatus(404)
                ->pushStatus(429)
                ->pushFailedConnection(),
            ]);

            $this->actingAs(User::factory()->create(['role' => 'editor']))
                ->post('/admin/updates/check')->assertForbidden();
            Http::assertNothingSent();

            $this->actingAs(User::factory()->create(['role' => 'owner']));
            $this->followingRedirects()->post('/admin/updates/check')->assertOk()
                ->assertInertia(fn (Page $page) => $page->where('data.release_check.status', 'available')
                    ->where('data.release_check.url', self::Download)->where('data.active_sequence', 1));
            foreach (['missing', 'missing', 'unavailable', 'unavailable'] as $status) {
                $this->post('/admin/updates/check')->assertRedirect('/admin/updates')
                    ->assertSessionHas('release_check', ['status' => $status, 'sequence' => 3]);
            }
            $this->assertSame($state, json_decode(File::get($directory.'/state.json'), true));
        } finally {
            File::deleteDirectory($storage);
        }
    }
}
