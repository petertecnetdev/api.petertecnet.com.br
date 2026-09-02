<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthLoginIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_email_username_cpf_and_phone(): void
    {
        $password = 'Test1234!';

        $emailUser = User::create([
            'first_name' => 'Email',
            'email' => 'email-login@example.test',
            'user_name' => 'email-login-user',
            'password' => Hash::make($password),
        ]);

        $usernameUser = User::create([
            'first_name' => 'Username',
            'email' => 'username-login@example.test',
            'user_name' => 'username-login-user',
            'password' => Hash::make($password),
        ]);

        $cpfUser = User::create([
            'first_name' => 'Cpf',
            'email' => 'cpf-login@example.test',
            'user_name' => 'cpf-login-user',
            'cpf' => '12345678901',
            'password' => Hash::make($password),
        ]);

        $phoneUser = User::create([
            'first_name' => 'Phone',
            'email' => 'phone-login@example.test',
            'user_name' => 'phone-login-user',
            'phone' => '(31) 99999-1234',
            'password' => Hash::make($password),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => strtoupper($emailUser->email),
            'password' => $password,
        ])->assertOk()->assertJsonPath('token.user.id', $emailUser->id);

        $this->postJson('/api/auth/login', [
            'username' => $usernameUser->user_name,
            'password' => $password,
        ])->assertOk()->assertJsonPath('token.user.id', $usernameUser->id);

        $this->postJson('/api/auth/login', [
            'username' => '123.456.789-01',
            'password' => $password,
        ])->assertOk()->assertJsonPath('token.user.id', $cpfUser->id);

        $this->postJson('/api/auth/login', [
            'username' => '31999991234',
            'password' => $password,
        ])->assertOk()->assertJsonPath('token.user.id', $phoneUser->id);

        $this->postJson('/api/auth/login', [
            'username' => '+55 (31) 99999-1234',
            'password' => $password,
        ])->assertOk()->assertJsonPath('token.user.id', $phoneUser->id);
    }

    public function test_non_email_non_cpf_non_phone_identifier_is_treated_as_username(): void
    {
        $credentials = User::credentials('  peter-user  ', 'secret');

        $this->assertSame([
            'user_name' => 'peter-user',
            'password' => 'secret',
        ], $credentials);
    }
}
