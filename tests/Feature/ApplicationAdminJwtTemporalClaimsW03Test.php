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
 * JWT temporal: o servidor deve validar o instante de ativação (nbf)
 * antes de resolver autoridade administrativa e aplicação.
 */
final class ApplicationAdminJwtTemporalClaimsW03Test extends TestCase
{
    use RefreshDatabase;

    public function test_jwt_assinado_com_nbf_futuro_nao_autoriza_leitura_ou_mutacao_cross_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');

        $owner = User::query()->create([
            'first_name' => 'Teste',
            'last_name' => 'W03',
            'email' => 'owner-jwt-nbf-futuro-w03@example.test',
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

        $jwtAtual = JWTAuth::fromUser($owner);
        $partes = explode('.', $jwtAtual);
        $this->assertCount(3, $partes);

        // O token é reassinado com o segredo legítimo: a rejeição deve
        // ocorrer pela validade temporal, não por assinatura adulterada.
        $cabecalho = json_decode(
            base64_decode(strtr($partes[0], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('HS256', $cabecalho['alg']);

        $payload = json_decode(
            base64_decode(strtr($partes[1], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['iat'] = now()->timestamp;
        $payload['nbf'] = now()->addHours(3)->timestamp;
        $payload['exp'] = now()->addHours(4)->timestamp;

        $payloadCodificado = rtrim(strtr(
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            '+/',
            '-_',
        ), '=');
        $segredo = (string) config('jwt.secret');
        $this->assertNotEmpty($segredo);

        $assinatura = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $partes[0].'.'.$payloadCodificado,
            $segredo,
            true,
        )), '+/', '-_'), '=');
        $jwtPrematuro = $partes[0].'.'.$payloadCodificado.'.'.$assinatura;
        $this->assertNotSame($jwtAtual, $jwtPrematuro);

        $cabecalhos = [
            'Authorization' => 'Bearer '.$jwtPrematuro,
            'X-Peter-App' => 'rasoio',
        ];
        $query = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;

        $this->getJson('/api/v1/apps/cutinapp/admin/audit'.$query, $cabecalhos)
            ->assertUnauthorized();

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles'.$query, [
            'name' => 'Perfil JWT prematuro W03',
            'permissions' => ['finance.refund'],
        ], $cabecalhos)->assertUnauthorized();

        $this->assertDatabaseCount('application_admin_profiles', 0);
        $this->assertDatabaseCount('application_admin_audits', 0);

        // Controle positivo: o token atual permanece válido somente
        // para a aplicação cujo vínculo foi persistido.
        $this->getJson('/api/v1/apps/cutinapp/admin/context', [
            'Authorization' => 'Bearer '.$jwtAtual,
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_owner', true);

        $this->assertFalse(app(ApplicationAdminService::class)->isRoot($owner));
    }

    /**
     * A validade temporal deve ser verificada antes da autoridade root global.
     * Um JWT assinado com o segredo correto, mas com nbf futuro, não pode
     * criar perfis, mesmo que pertença ao root do ecossistema.
     */
    public function test_root_global_com_jwt_prematuro_nao_executa_mutacoes_administrativas(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = User::query()->create([
            'first_name' => 'Root',
            'last_name' => 'W03',
            'email' => ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        $this->assertTrue(app(ApplicationAdminService::class)->isRoot($root));
        $tokenValido = JWTAuth::fromUser($root);
        $partes = explode('.', $tokenValido);
        $this->assertCount(3, $partes);

        $payload = json_decode(
            base64_decode(strtr($partes[1], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['nbf'] = now()->addHours(2)->timestamp;
        $payload['exp'] = now()->addHours(3)->timestamp;

        $payloadCodificado = rtrim(strtr(
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            '+/',
            '-_',
        ), '=');
        $assinatura = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $partes[0].'.'.$payloadCodificado,
            (string) config('jwt.secret'),
            true,
        )), '+/', '-_'), '=');
        $tokenPrematuro = $partes[0].'.'.$payloadCodificado.'.'.$assinatura;

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles?app_id='.$cutinapp->id, [
            'name' => 'Perfil proibido root prematuro W03',
            'permissions' => ['operations.view'],
        ], [
            'Authorization' => 'Bearer '.$tokenPrematuro,
            'X-Peter-App' => 'rasoio',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('application_admin_profiles', 0);
        $this->assertDatabaseCount('application_admin_audits', 0);

        // Controle positivo: o JWT legítimo mantém a autoridade global.
        $this->getJson('/api/v1/apps/cutinapp/admin/context', [
            'Authorization' => 'Bearer '.$tokenValido,
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_root', true);
    }

}
