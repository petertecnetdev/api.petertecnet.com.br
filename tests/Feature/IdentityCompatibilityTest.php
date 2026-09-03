<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Services\TotpService;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_login_url_issues_a_centralized_session(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Test1234!',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session.auth_method', 'password')
            ->assertJsonStructure(['access_token', 'session' => ['id']]);
    }

    public function test_legacy_login_url_cannot_bypass_two_factor(): void
    {
        $user = $this->user();
        IdentitySecuritySetting::query()->create([
            'user_id' => $user->id,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TotpService::class)->generateSecret(),
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => $user->email,
            'password' => 'Test1234!',
        ])->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissing(['access_token']);
    }

    private function user(): User
    {
        $profile = Profile::query()->create(['name' => 'Usuário', 'permissions' => []]);

        return User::query()->create([
            'first_name' => 'Compatibilidade',
            'email' => 'compat@example.test',
            'user_name' => 'compat-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
    }
}
