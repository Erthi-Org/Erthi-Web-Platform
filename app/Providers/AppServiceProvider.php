<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Timebox;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('manage-erthi', fn (User $user): bool => in_array($user->role, ['owner', 'editor'], true));

        Fortify::loginView(fn () => Inertia::render('Login', ['locale' => 'ar']));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('AuthenticationChallenge', ['locale' => 'ar', 'factor' => true]));
        Fortify::confirmPasswordView(fn () => Inertia::render('AuthenticationChallenge', ['locale' => 'ar', 'factor' => false]));

        Fortify::authenticateUsing(function (Request $request): ?User {
            return (new Timebox)->call(function () use ($request): ?User {
                $user = User::where('email', strtolower(trim((string) $request->input('email'))))->first();
                $password = (string) $request->input('password');
                if (! $user || strlen($password) > 72 || ! Hash::check($password, $user->password) || ! $user->can('manage-erthi')) {
                    return null;
                }
                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => Hash::make($password)])->save();
                }

                return $user;
            }, 200000);
        });

        Passkeys::authorizeLoginUsing(fn (Request $request, User $user): bool => $user->can('manage-erthi'));
        RateLimiter::for('erthi-password-login', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('erthi-two-factor', fn (Request $request) => Limit::perMinute(6)->by($request->session()->get('login.id').'|'.$request->ip()));
        RateLimiter::for('erthi-passkeys', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));
    }
}
