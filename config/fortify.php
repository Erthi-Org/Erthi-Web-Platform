<?php

use App\Http\Middleware\ErthiAuthentication;
use Illuminate\Support\Arr;
use Laravel\Fortify\Features;

$admin = '/'.config('erthi.admin_prefix', 'admin');
$paths = [
    'login' => $admin === '/admin' ? '/login' : $admin.'/access',
    'logout' => $admin === '/admin' ? '/logout' : $admin.'/exit',
];

foreach ([
    'password.confirm' => '/confirm-password',
    'password.confirmation' => '/confirmed-password-status',
    'two-factor.login' => '/two-factor-challenge',
    'two-factor.enable' => '/two-factor',
    'two-factor.disable' => '/two-factor',
    'two-factor.confirm' => '/two-factor/confirm',
    'two-factor.qr-code' => '/two-factor/qr',
    'two-factor.secret-key' => '/two-factor/secret',
    'two-factor.recovery-codes' => '/two-factor/recovery',
    'passkey.login-options' => '/passkeys/login/options',
    'passkey.login' => '/passkeys/login',
    'passkey.confirm-options' => '/passkeys/confirm/options',
    'passkey.confirm' => '/passkeys/confirm',
    'passkey.registration-options' => '/passkeys/options',
    'passkey.store' => '/passkeys',
    'passkey.destroy' => '/passkeys/{passkey}',
] as $name => $suffix) {
    Arr::set($paths, $name, $admin.$suffix);
}

return [
    'guard' => 'web',
    'username' => 'email',
    'lowercase_usernames' => true,
    'home' => $admin,
    'middleware' => ['web', ErthiAuthentication::class],
    'paths' => $paths,
    'redirects' => ['login' => $admin, 'logout' => '/ar', 'password-confirmation' => $admin.'/security'],
    'limiters' => ['login' => 'erthi-password-login', 'two-factor' => 'erthi-two-factor', 'passkeys' => 'erthi-passkeys'],
    'features' => [
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        Features::passkeys(['confirmPassword' => true]),
    ],
];
