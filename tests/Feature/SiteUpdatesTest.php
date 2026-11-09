<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

final class SiteUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_can_enroll_and_install_verified_sequential_packages(): void
    {
        $this->assertTrue(extension_loaded('sodium') && class_exists(ZipArchive::class));
        $private = sys_get_temp_dir().'/erthi-updates-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($private);
        $path = 'resources/data/test-update-'.bin2hex(random_bytes(8)).'.json';
        $absolute = base_path($path);
        $added = str_replace('.json', '-added.json', $path);
        File::put($absolute, 'original');

        try {
            $pair = sodium_crypto_sign_keypair();
            $key = base64_encode(sodium_crypto_sign_publickey($pair));
            $secret = sodium_crypto_sign_secretkey($pair);
            $editor = User::factory()->create(['role' => 'editor']);
            $this->actingAs($editor)->get('/admin/updates')->assertForbidden();
            $this->post('/admin/updates', ['key' => $key, 'password' => 'password'])->assertForbidden();
            $this->post('/admin/updates/rollback', ['password' => 'password'])->assertForbidden();

            $this->actingAs(User::factory()->create(['role' => 'owner']));
            $this->post('/admin/updates', ['key' => $key, 'password' => 'wrong'])->assertSessionHasErrors('password');
            $this->post('/admin/updates', ['key' => $key, 'password' => 'password'])->assertSessionHasNoErrors();

            $post = function (int $sequence, string $name, string $content, string $signedContent, ?string $signingKey = null, array $extra = []) use ($private, $secret) {
                $bundle = $private.'/test.zip';
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($bundle, ZipArchive::CREATE | ZipArchive::OVERWRITE));
                $manifest = json_encode(['product' => 'erthi-web', 'sequence' => $sequence, 'files' => [$name => hash('sha256', $signedContent)] + array_map(fn ($bytes) => hash('sha256', $bytes), $extra)], JSON_THROW_ON_ERROR);
                $zip->addFromString('manifest.json', $manifest);
                $zip->addFromString('signature.ed25519', sodium_crypto_sign_detached($manifest, $signingKey ?? $secret));
                $zip->addFromString('files/'.$name, $content);
                foreach ($extra as $extraPath => $bytes) {
                    $zip->addFromString('files/'.$extraPath, $bytes);
                }
                $zip->close();

                return $this->post('/admin/updates', ['bundle' => new UploadedFile($bundle, 'update.zip', null, null, true), 'password' => 'password']);
            };

            $post(1, $path, 'tampered', 'correct')->assertStatus(302)->assertSessionHasErrors('bundle');
            $otherKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
            $post(1, $path, 'wrong-key', 'wrong-key', $otherKey)->assertSessionHasErrors('bundle');
            $post(1, '../outside.json', 'wrong-path', 'wrong-path')->assertSessionHasErrors('bundle');
            $this->assertSame('original', File::get($absolute));
            $this->travel(1)->minutes();
            $post(2, $path, 'skipped', 'skipped')->assertSessionHasErrors('bundle');
            $post(1, $path, 'correct', 'correct', extra: [$added => 'new'])->assertSessionHasNoErrors();
            $this->assertSame('correct', File::get($absolute));
            $post(1, $path, 'replay', 'replay')->assertSessionHasErrors('bundle');
            $this->assertSame('correct', File::get($absolute));

            $this->travel(1)->minutes();
            $this->post('/admin/updates/rollback', ['password' => 'wrong'])->assertSessionHasErrors('password');
            $this->post('/admin/updates/rollback', ['password' => 'password'])->assertSessionHasNoErrors();
            $this->assertSame('original', File::get($absolute));
            $this->assertFileDoesNotExist(base_path($added));
            $state = app(SiteUpdates::class)->status();
            $this->assertSame(1, $state['sequence']);
            $this->assertSame(0, $state['active_sequence']);
            $post(1, $path, 'replay', 'replay')->assertSessionHasErrors('bundle');
            $post(2, $path, 'next', 'next')->assertSessionHasNoErrors();
            $this->assertSame('next', File::get($absolute));
        } finally {
            File::delete([$absolute, base_path($added)]);
            File::deleteDirectory($private);
        }
    }
}
