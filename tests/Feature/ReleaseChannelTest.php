<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;
use ZipArchive;

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

    public function test_owner_installs_a_signed_release_from_github_without_uploading_it(): void
    {
        $storage = sys_get_temp_dir().'/erthi-release-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($storage);
        $path = 'resources/data/test-release-'.bin2hex(random_bytes(8)).'.json';
        $cdn = 'https://release-assets.githubusercontent.com/update.zip';
        $install = '/admin/updates/install-release/3';
        $updates = app(SiteUpdates::class);
        $pair = sodium_crypto_sign_keypair();
        $directory = $storage.'/app/private/site-updates';
        File::ensureDirectoryExists($directory);
        File::put($directory.'/state.json', json_encode([
            'key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'sequence' => 2,
        ], JSON_THROW_ON_ERROR));

        try {
            $bundle = $storage.'/release.zip';
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($bundle, ZipArchive::CREATE));
            $manifest = json_encode([
                'product' => 'erthi-web', 'sequence' => 3, 'files' => [$path => hash('sha256', '{}')],
            ], JSON_THROW_ON_ERROR);
            $zip->addFromString('manifest.json', $manifest);
            $zip->addFromString('signature.ed25519', sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($pair)));
            $zip->addFromString('files/'.$path, '{}');
            $zip->close();

            Http::preventStrayRequests();
            Http::fake([
                self::Endpoint => Http::response(['tag_name' => 'erthi-update-3', 'draft' => false, 'assets' => [
                    ['name' => 'erthi-update-3.zip', 'state' => 'uploaded', 'browser_download_url' => self::Download],
                ]]),
                self::Download => Http::sequence()
                    ->pushStatus(302, ['Location' => 'https://elsewhere.example/update.zip'])
                    ->push('invalid zip')
                    ->pushStatus(302, ['Location' => $cdn]),
                $cdn => Http::response(File::get($bundle)),
            ]);

            $this->actingAs(User::factory()->create(['role' => 'editor']))
                ->post($install, ['password' => 'password'])->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => 'owner']))
                ->post($install, ['password' => 'password'])->assertSessionHasErrors('password');
            Http::assertNothingSent();

            $this->post('/admin/updates/check')->assertRedirect('/admin/updates');
            $this->post($install, ['password' => 'wrong'])->assertSessionHasErrors('password');
            Http::assertSentCount(1);

            $this->post($install, ['password' => 'password'])->assertSessionHasErrors('password');
            $this->post($install, ['password' => 'password'])->assertSessionHasErrors('password');
            $this->assertFileDoesNotExist(base_path($path));
            $this->assertSame(2, $updates->status()['sequence']);

            $this->travel(1)->minutes();
            $this->from('/admin/updates')->post($install, ['password' => 'password'])
                ->assertRedirect('/admin/updates')->assertSessionHasNoErrors()->assertSessionMissing('release_check');
            $this->assertSame('{}', File::get(base_path($path)));
            $this->assertSame(3, $updates->status()['active_sequence']);
            $this->post($install, ['password' => 'password'])->assertSessionHasErrors('password');
            Http::assertSentCount(8);
        } finally {
            File::delete(base_path($path));
            File::deleteDirectory($storage);
        }
    }
}
