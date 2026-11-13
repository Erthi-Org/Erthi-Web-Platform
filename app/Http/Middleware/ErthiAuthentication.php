<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ErthiAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $request->routeIs('logout')) {
            abort_unless($user->role === 'owner', 403);
        }

        if ($request->routeIs('two-factor.login', 'two-factor.login.store') && $request->session()->has('login.id')) {
            $challenged = User::find($request->session()->get('login.id'));
            abort_unless($challenged !== null && in_array($challenged->role, ['owner', 'editor'], true), 403);
        }

        if ($request->routeIs('password.confirm.store')) {
            $key = 'erthi-password-confirm:'.$user?->getAuthIdentifier().'|'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($key, 6), 429);
            RateLimiter::hit($key);
            $request->validate(['password' => ['required', 'string', 'max:72']]);
        }

        $request->merge(['remember' => false]);

        return $next($request);
    }
}
