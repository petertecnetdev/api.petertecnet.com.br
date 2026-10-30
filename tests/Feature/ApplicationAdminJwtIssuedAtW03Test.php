<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Domain\Platform\Services\ApplicationOwnerAuthorityService;
use App\Models\ApplicationOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Um JWT com assinatura legítima não pode antecipar sua emissão (iat)
 * para obter acesso administrativo antes da validação temporal.
 */
final class ApplicationAdminJwtIssuedAtW03Test extends TestCase
{
    use RefreshDatabase;

    public function test_jwt_com_iat_futuro_nao_autoriza_leitura_nem_mutacao_administrativa(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $owner = User::query()->create([
            'first_name' => 'Owner',
            'last_name' => 'W03',
            'email' => 'owner-jwt-iat-futuro-w03@example.test',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $owner->id,
            'bootstrap_email' => ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $owner->id,
        ]);

        $tokenAtual = JWTAuth::fromUser($owner);
        $partes = explode('.', $tokenAtual);
        $this->assertCount(3, $partes);

        $cabecalho = json_decode(base64_decode(strtr($partes[0], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('HS256', $cabecalho['alg']);
        $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);

        // nbf continua válido. Somente iat é adiantado, com exp posterior,
        // isolando a regra de emissão da regra de ativação (nbf).
        $payload['iat'] = now()->addHours(2)->timestamp;
        $payload['exp'] = now()->addHours(3)->timestamp;
        $this->assertLessThan($payload['iat'], $payload['nbf']);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);

        $payloadCodificado = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $segredo = (string) config('jwt.secret');
        $this->assertNotEmpty($segredo);
        $assinatura = rtrim(strtr(base64_encode(hash_hmac(
            'sha256', $partes[0].'.'.$payloadCodificado, $segredo, true,
        )), '+/', '-_'), '=');
        $tokenFuturo = $partes[0].'.'.$payloadCodificado.'.'.$assinatura;
        $this->assertNotSame($tokenAtual, $tokenFuturo);

        $cabecalhos = [
            'Authorization' => 'Bearer '.$tokenFuturo,
            'X-Peter-App' => 'rasoio',
        ];
        $query = '?app_id='.$externo->id.'&application_id='.$externo->id;

        $this->getJson('/api/v1/apps/cutinapp/admin/audit'.$query, $cabecalhos)
            ->assertUnauthorized();
        $this->postJson('/api/v1/apps/cutinapp/admin/profiles'.$query, [
            'name' => 'Perfil JWT iat futuro W03',
            'permissions' => ['finance.refund'],
        ], $cabecalhos)->assertUnauthorized();

        $this->assertDatabaseCount('application_admin_profiles', 0);
        $this->assertDatabaseCount('application_admin_audits', 0);

        // O controle positivo prova que o usuário e a aplicação são válidos:
        // somente o instante de emissão inválido deve bloquear o token.
        $this->getJson('/api/v1/apps/cutinapp/admin/context', [
            'Authorization' => 'Bearer '.$tokenAtual,
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_owner', true);
        $this->assertFalse(app(ApplicationAdminService::class)->isRoot($owner));
    }
}
