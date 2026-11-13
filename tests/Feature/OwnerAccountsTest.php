<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class OwnerAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        return [
            'name' => 'Second owner',
            'email' => ' SECOND@example.test ',
            'role' => 'owner',
            'password' => 'independent-owner-password',
            'password_confirmation' => 'independent-owner-password',
            'current_password' => 'password',
            'identity_confirmed' => true,
        ];
    }

    public function test_owner_can_create_an_independent_owner_with_a_safe_audit_record(): void
    {
        $storage = sys_get_temp_dir().'/erthi-accounts-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($storage);

        try {
            $owner = User::factory()->create(['role' => 'owner']);
            $this->actingAs($owner)->from('/admin/users')->post('/admin/users', $this->account())
                ->assertRedirect('/admin/users')->assertSessionHasNoErrors();

            $second = User::where('email', 'second@example.test')->firstOrFail();
            $this->assertSame('owner', $second->role);
            $this->assertTrue(Hash::check($this->account()['password'], $second->password));
            $this->assertTrue(Hash::check('password', $owner->fresh()->password));
            $this->get('/admin/audit')->assertOk()
                ->assertDontSee($this->account()['password'])
                ->assertDontSee($this->account()['password_confirmation'])
                ->assertDontSee('second@example.test')
                ->assertInertia(fn (Page $page) => $page->has('data', 1)
                    ->where('data.0.actor', $owner->id)
                    ->where('data.0.target', $second->id)
                    ->where('data.0.role', 'owner'));

            $this->post('/logout')->assertRedirect('/ar');
            $this->post('/login', ['email' => $second->email, 'password' => $this->account()['password']])
                ->assertRedirect('/admin');
            $this->assertAuthenticatedAs($second);
            $this->get('/admin/users')->assertOk();
            $this->post('/admin/users/'.$owner->id, ['role' => 'viewer'])->assertNotFound();
            $this->post('/admin/users/'.$second->id, ['role' => 'editor'])->assertNotFound();
        } finally {
            File::deleteDirectory($storage);
        }
    }

    public function test_creation_requires_owner_authorization_and_valid_confirmation(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/admin/users', $this->account())->assertForbidden();

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->from('/admin/users');
        foreach ([
            ['current_password', ['current_password' => 'incorrect']],
            ['password', ['password_confirmation' => 'different']],
            ['password', ['password' => str_repeat('أ', 40), 'password_confirmation' => str_repeat('أ', 40)]],
            ['identity_confirmed', ['identity_confirmed' => false]],
            ['role', ['role' => 'admin']],
            ['email', ['email' => strtoupper($owner->email)]],
        ] as [$field, $changes]) {
            $this->post('/admin/users', array_replace($this->account(), $changes))
                ->assertSessionHasErrors($field);
            $this->assertNull(session()->getOldInput('current_password'));
            $this->assertNull(session()->getOldInput('password'));
            $this->assertNull(session()->getOldInput('password_confirmation'));
        }

        $this->assertDatabaseCount('users', 2);
    }

    public function test_repeated_account_creation_attempts_are_rate_limited(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post('/admin/users', [])->assertSessionHasErrors('current_password');
        }

        $this->post('/admin/users', $this->account())->assertTooManyRequests();
        $this->assertDatabaseCount('users', 1);
    }
}
