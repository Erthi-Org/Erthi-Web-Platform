<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class OwnerPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_an_independent_owner_who_can_sign_in(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $payload = $this->account();

        $this->actingAs($owner)->from('/admin/users')->post('/admin/users', $payload)
            ->assertRedirect('/admin/users')->assertSessionHasNoErrors();
        $second = User::where('email', 'second@example.test')->firstOrFail();
        $this->assertSame('owner', $second->role);
        $this->assertTrue(Hash::check($payload['password'], $second->password));
        $this->assertTrue(Hash::check('password', $owner->refresh()->password));

        $this->post('/admin/users/'.$second->id, ['role' => 'viewer'])->assertNotFound();
        $this->post('/logout')->assertRedirect('/ar');
        $this->post('/login', ['email' => $second->email, 'password' => $payload['password']])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($second);
        $this->get('/admin/users')->assertOk();
    }

    public function test_creating_an_owner_requires_password_confirmation_and_trusted_identity(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->post('/admin/users', $this->account())->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        foreach ([
            ['current_password' => 'invalid', 'error' => 'current_password'],
            ['password_confirmation' => 'invalid', 'error' => 'password'],
            ['identity_confirmed' => false, 'error' => 'identity_confirmed'],
        ] as $invalid) {
            $error = $invalid['error'];
            unset($invalid['error']);
            $this->post('/admin/users', $invalid + $this->account())->assertSessionHasErrors($error);
        }
        $owner = User::factory()->create(['role' => 'owner', 'password' => $this->account()['password']]);
        $this->actingAs($owner)->post('/admin/users', ['current_password' => $this->account()['password']] + $this->account())
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

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

    private function account(): array
    {
        return [
            'name' => 'Second owner',
            'email' => ' SECOND@example.test ',
            'role' => 'owner',
            'password' => 'a-separate-password-for-the-test',
            'password_confirmation' => 'a-separate-password-for-the-test',
            'current_password' => 'password',
            'identity_confirmed' => true,
        ];
    }
}
