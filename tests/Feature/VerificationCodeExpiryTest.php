<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerificationCodeExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_verification_code_is_rate_limited_per_authenticated_user(): void
    {
        Mail::fake();

        $user = User::create([
            'first_name' => 'Teste',
            'email' => 'resend-rate-limit@example.test',
            'user_name' => 'resend-rate-limit',
            'password' => Hash::make('Test1234!'),
        ]);

        $token = auth('api')->login($user);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/auth/resend-code-email-verification')
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/auth/resend-code-email-verification')
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after'])
            ->assertJsonPath('retry_after', 60);
    }

    public function test_expired_email_verification_code_is_rejected(): void
    {
        $code = 'ABC234';
        $user = User::create([
            'first_name' => 'Teste',
            'email' => 'verify@example.test',
            'user_name' => 'verify-test',
            'password' => Hash::make('Test1234!'),
            'verification_code' => Hash::make($code),
            'verification_code_expires_at' => now()->subMinute(),
        ]);

        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/email-verify', ['verification_code' => $code])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
    }
}
