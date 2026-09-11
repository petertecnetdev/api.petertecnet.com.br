<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppUserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_invitation_can_activate_new_user_and_enable_phone_login(): void
    {
        config()->set('services.whatsapp.enabled', true);
        config()->set('services.whatsapp.graph_version', 'v26.0');
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.access_token', 'test-token');
        config()->set('services.whatsapp.language', 'pt_BR');
        config()->set('services.whatsapp.invitation_template', 'petertecnet_user_invitation');
        config()->set('services.whatsapp.authentication_template', 'petertecnet_authentication_code');
        config()->set('services.whatsapp.activation_template', 'petertecnet_account_activated');

        Http::fakeSequence()
            ->push([
                'messages' => [['id' => 'wamid.welcome']],
            ], 200)
            ->push([
                'messages' => [['id' => 'wamid.otp']],
            ], 200)
            ->push([
                'messages' => [['id' => 'wamid.activated']],
            ], 200);

        $application = $this->applicationFixture('plat', [
            'name' => 'Plat',
            'url' => 'https://plat.petertecnet.com.br',
            'is_active' => true,
        ]);

        $service = app(UserInvitationService::class);

        $created = $service->createProspect([
            'channel' => 'whatsapp',
            'phone' => '(62) 99999-9999',
            'recipient_name' => 'Cliente Teste',
            'application_id' => $application->id,
            'persona' => 'client',
            'whatsapp_consent' => true,
        ], null);

        $this->assertSame(201, $created['status']);

        $invitation = UserInvitation::query()->firstOrFail();
        $user = User::query()->firstOrFail();

        $this->assertNull($user->email);
        $this->assertSame('+5562999999999', $user->phone_normalized);
        $this->assertNull($user->whatsapp_verified_at);
        $this->assertSame('whatsapp', $invitation->channel);
        $this->assertSame('+5562999999999', $invitation->destination);
        $this->assertSame('sent', $invitation->delivery_status);
        $this->assertSame('wamid.otp', $invitation->provider_message_id);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        $welcomePayload = $requests[0][0]->data();
        $otpPayload = $requests[1][0]->data();

        $activationUrl = $welcomePayload['template']['components'][0]['parameters'][2]['text'];
        parse_str((string) parse_url($activationUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;

        $code = $otpPayload['template']['components'][0]['parameters'][0]['text'] ?? null;

        $this->assertNotEmpty($token);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $shown = $service->showByToken((string) $token);
        $this->assertSame(200, $shown['status']);
        $this->assertSame('whatsapp', $shown['body']['channel']);
        $this->assertSame('+5562999999999', $shown['body']['phone']);
        $this->assertTrue($shown['body']['requires_password']);

        $activated = $service->activate((string) $token, (string) $code, 'Senha#Forte123');

        $this->assertSame(200, $activated['status']);
        $this->assertSame('whatsapp', $activated['body']['channel']);

        $user->refresh();
        $invitation->refresh();

        $this->assertNotNull($user->whatsapp_verified_at);
        $this->assertTrue(Hash::check('Senha#Forte123', $user->password));
        $this->assertSame('accepted', $invitation->status);

        $this->assertDatabaseHas('application_user', [
            'user_id' => $user->id,
            'application_id' => $application->id,
            'status' => 'active',
        ]);

        $credentials = User::credentials('(62) 99999-9999', 'Senha#Forte123');
        $this->assertSame('+5562999999999', $credentials['phone_normalized'] ?? null);
        $this->assertSame('Senha#Forte123', $credentials['password'] ?? null);

        $this->assertTrue(auth()->attempt($credentials));

        $this->assertCount(3, Http::recorded());
    }

    public function test_whatsapp_invitation_requires_explicit_contact_consent(): void
    {
        config()->set('services.whatsapp.enabled', true);
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.access_token', 'test-token');
        config()->set('services.whatsapp.invitation_template', 'petertecnet_user_invitation');
        config()->set('services.whatsapp.authentication_template', 'petertecnet_authentication_code');

        $application = $this->applicationFixture('rasoio', [
            'name' => 'Rasoio',
            'url' => 'https://rasoio.petertecnet.com.br',
            'is_active' => true,
        ]);

        $result = app(UserInvitationService::class)->createProspect([
            'channel' => 'whatsapp',
            'phone' => '62999999999',
            'recipient_name' => 'Sem Consentimento',
            'application_id' => $application->id,
            'persona' => 'client',
            'whatsapp_consent' => false,
        ], null);

        $this->assertSame(422, $result['status']);
        $this->assertDatabaseCount('user_invitations', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }
}
