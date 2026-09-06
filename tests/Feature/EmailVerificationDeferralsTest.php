<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EmailVerificationDeferrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationDeferralsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_only_email_verification_deferral_metadata(): void
    {
        $user = $this->createUser(false, [
            EmailVerificationDeferrals::DEFERRALS_KEY => 2,
            EmailVerificationDeferrals::LAST_DEFERRED_AT_KEY => '2026-09-06T18:00:00-03:00',
            'preserved_key' => 'keep-me',
        ]);

        $before = EmailVerificationDeferrals::state($user);
        $after = EmailVerificationDeferrals::reset($user);

        $this->assertSame(2, $before['deferrals_used']);
        $this->assertTrue($before['confirmation_required']);
        $this->assertSame(0, $after['deferrals_used']);
        $this->assertSame(2, $after['deferrals_remaining']);
        $this->assertTrue($after['can_defer']);
        $this->assertFalse($after['confirmation_required']);
        $this->assertNull($after['last_deferred_at']);

        $user->refresh();
        $this->assertSame('keep-me', $user->extra_info['preserved_key']);
        $this->assertArrayNotHasKey(EmailVerificationDeferrals::DEFERRALS_KEY, $user->extra_info);
        $this->assertArrayNotHasKey(EmailVerificationDeferrals::LAST_DEFERRED_AT_KEY, $user->extra_info);
    }

    public function test_verified_user_stays_ineligible_to_defer_after_reset(): void
    {
        $user = $this->createUser(true, [
            EmailVerificationDeferrals::DEFERRALS_KEY => 2,
        ]);

        $state = EmailVerificationDeferrals::reset($user);

        $this->assertTrue($state['verified']);
        $this->assertFalse($state['can_defer']);
        $this->assertFalse($state['confirmation_required']);
    }

    private function createUser(bool $verified, array $extraInfo): User
    {
        return User::query()->create([
            'first_name' => 'Teste',
            'email' => 'email-deferrals-'.Str::uuid().'@example.com',
            'password' => Hash::make('test-password'),
            'email_verified_at' => $verified ? now() : null,
            'extra_info' => $extraInfo,
        ]);
    }
}
