<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityRuntimeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_login_executes_without_hidden_runtime_exception(): void
    {
        $this->withoutExceptionHandling();
        config()->set('identity.password.compromised_check', false);
        config()->set('identity.global_sso.cache_store', 'array');

        $user = User::query()->create([
            'first_name' => 'Runtime',
            'email' => 'runtime-identity@example.test',
            'user_name' => 'runtime-identity',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/account/identity/login', [
            'username' => $user->email,
            'password' => 'Test1234!',
        ])->assertOk()->assertJsonPath('user.id', $user->id);
    }
}
