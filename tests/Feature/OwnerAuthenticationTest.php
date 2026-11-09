<?php

namespace Tests\Feature;

use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Page;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class OwnerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_enroll_totp_and_complete_login_with_one_time_recovery_code(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->postJson('/admin/two-factor')->assertStatus(423);
        $this->postJson('/admin/confirm-password', ['password' => 'password'])->assertCreated();
        $this->postJson('/admin/two-factor')->assertOk();
        $this->getJson('/admin/two-factor/qr')->assertOk()->assertJsonStructure(['svg', 'url']);
        $this->postJson('/admin/two-factor/confirm', ['code' => 'invalid'])->assertUnprocessable();
        $code = (new Google2FA)->getCurrentOtp(decrypt($owner->fresh()->two_factor_secret));
        $this->postJson('/admin/two-factor/confirm', ['code' => $code])->assertOk();
        $recovery = $this->getJson('/admin/two-factor/recovery')->assertOk()->json();
        $this->assertCount(8, $recovery);
        $this->post('/logout')->assertRedirect('/ar');

        $credentials = ['email' => $owner->email, 'password' => 'password'];
        $this->post('/login', $credentials)->assertRedirect('/admin/two-factor-challenge');
        $this->assertGuest();
        $this->post('/admin/two-factor-challenge', ['recovery_code' => $recovery[0]])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($owner);
        $this->assertNotContains($recovery[0], $owner->fresh()->recoveryCodes());

        $this->post('/logout')->assertRedirect('/ar');
        $this->post('/login', $credentials)->assertRedirect('/admin/two-factor-challenge');
        $this->post('/admin/two-factor-challenge', ['recovery_code' => $recovery[0]])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_passkey_endpoints_issue_fresh_challenges_and_require_recent_password_for_enrollment(): void
    {
        $this->postJson('/admin/passkeys/login', [])->assertUnprocessable();
        $first = $this->getJson('/admin/passkeys/login/options')->assertOk()->json('options.challenge');
        $second = $this->getJson('/admin/passkeys/login/options')->assertOk()->json('options.challenge');
        $this->assertIsString($first);
        $this->assertNotSame($first, $second);
        $this->assertGuest();

        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->getJson('/admin/passkeys/options')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->getJson('/admin/passkeys/options')->assertStatus(423);
        $this->postJson('/admin/confirm-password', ['password' => 'password'])->assertCreated();
        $this->getJson('/admin/passkeys/options')->assertOk()->assertJsonStructure(['options' => ['challenge', 'rp', 'user']]);
        $this->postJson('/admin/passkeys', ['name' => 'Invalid', 'credential' => []])->assertUnprocessable();
        $this->assertDatabaseEmpty('passkeys');
    }

    public function test_owner_can_register_use_and_delete_a_cryptographic_passkey(): void
    {
        config(['passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost']]);
        $owner = User::factory()->create(['role' => 'owner']);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $client = fn (string $type, string $challenge): string => json_encode([
            'type' => $type, 'challenge' => $challenge, 'origin' => 'http://localhost',
        ], JSON_THROW_ON_ERROR);

        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $coordinates = openssl_pkey_get_details($privateKey)['ec'];
        $publicKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($coordinates['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($coordinates['y'], 32, "\0", STR_PAD_LEFT)));
        $identifier = random_bytes(32);
        $relyingParty = hash('sha256', 'localhost', true);
        $registrationData = $relyingParty.chr(0x45).pack('N', 0).str_repeat("\0", 16)
            .pack('n', strlen($identifier)).$identifier.$publicKey;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($registrationData));
        $credential = ['id' => $encode($identifier), 'rawId' => $encode($identifier), 'type' => 'public-key'];

        $this->actingAs($owner)->postJson('/admin/confirm-password', ['password' => 'password'])->assertCreated();
        $registration = $this->getJson('/admin/passkeys/options')->assertOk()->json('options');
        $id = $this->postJson('/admin/passkeys', ['name' => 'Test authenticator', 'credential' => $credential + [
            'response' => [
                'clientDataJSON' => $encode($client('webauthn.create', $registration['challenge'])),
                'attestationObject' => $encode((string) $attestation),
            ],
        ]])->assertOk()->json('id');
        $this->assertDatabaseHas('passkeys', ['id' => $id, 'user_id' => $owner->id]);
        $this->post('/logout')->assertRedirect('/ar');

        $verification = $this->getJson('/admin/passkeys/login/options')->assertOk()->json('options');
        $clientData = $client('webauthn.get', $verification['challenge']);
        $authenticatorData = $relyingParty.chr(0x05).pack('N', 1);
        $this->assertTrue(openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $assertion = ['credential' => $credential + ['response' => [
            'clientDataJSON' => $encode($clientData),
            'authenticatorData' => $encode($authenticatorData),
            'signature' => $encode($signature),
            'userHandle' => $registration['user']['id'],
        ]]];
        $this->postJson('/admin/passkeys/login', $assertion)->assertOk();
        $this->assertAuthenticatedAs($owner);
        $this->post('/logout')->assertRedirect('/ar');
        $this->postJson('/admin/passkeys/login', $assertion)->assertUnprocessable();
        $this->assertGuest();

        $this->actingAs($owner)->postJson('/admin/confirm-password', ['password' => 'password'])->assertCreated();
        $this->deleteJson('/admin/passkeys/'.$id)->assertOk();
        $this->assertDatabaseEmpty('passkeys');
    }

    public function test_file_scan_is_owner_only_and_reports_unavailable_without_a_daemon(): void
    {
        config(['erthi.clamd_socket' => null]);
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/admin/safescan')->assertForbidden();
        $this->post('/admin/safescan')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/admin/safescan')->assertInertia(fn (Page $page) => $page->where('data.configured', false));
        $this->post('/admin/safescan', ['file' => UploadedFile::fake()->create('big.txt', 10241)])
            ->assertSessionHasErrors('file');
        $this->post('/admin/safescan', ['file' => UploadedFile::fake()->createWithContent('sample.txt', 'sample')])
            ->assertRedirect('/admin/safescan')->assertSessionHas('scan_result', 'unavailable');
    }
}
