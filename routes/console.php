<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('erthi:owner {email}', function (): int {
    $email = strtolower($this->argument('email'));
    $password = $this->secret('Owner password (14–72 characters)');
    $validation = Validator::make(compact('email', 'password'), [
        'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
        'password' => ['required', 'string', 'min:14', 'max:72', 'regex:/\A[^\x00]{14,72}\z/'],
    ]);
    if ($validation->fails()) {
        $this->error($validation->errors()->first());

        return 1;
    }
    (new User)->forceFill(['name' => 'Erthi', 'email' => $email, 'password' => Hash::make($password), 'role' => 'owner'])->save();
    $this->info('Owner account created.');

    return 0;
})->purpose('Create an Erthi owner without storing a default password');
