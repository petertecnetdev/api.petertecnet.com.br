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
 * Contrato de propriedade por aplicação: um vínculo local nunca autoriza
 * tomar outra aplicação nem substituir o proprietário já registrado.
 *
 * Esta suíte é independente de ApplicationAdminService::context(), cujo
 * contrato is_root/is_owner permanece sob correção do responsável API.
 */
final class ApplicationAdminOwnerAuthorityW03Test extends TestCase
{
    use RefreshDatabase;

    public function test_proprietario_local_nao_herda_propriedade_de_outra_aplicacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-local-w03@example.test');
        $autoridade = app(ApplicationOwnerAuthorityService::class);

        $this->vincular($cutinapp->id, $local);

        $this->assertTrue($autoridade->isOwner($local, $cutinapp->id));
        $this->assertFalse($autoridade->isOwner($local, $externo->id));
        $this->assertSame($local->id, $autoridade->ownerId($cutinapp->id));
        $this->assertNull($autoridade->ownerId($externo->id));
        $this->assertFalse(app(ApplicationAdminService::class)->isRoot($local));
    }

    public function test_bootstrap_global_nao_substitui_proprietario_local_ja_vinculado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $local = $this->usuario('owner-vinculado-w03@example.test');
        $root = $this->usuario(ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL);
        $autoridade = app(ApplicationOwnerAuthorityService::class);

        $this->vincular($cutinapp->id, $local);

        $this->assertTrue($autoridade->matchesBootstrapEmail($root));
        $this->assertFalse($autoridade->isOwner($root, $cutinapp->id));
        $this->assertTrue($autoridade->isOwner($local, $cutinapp->id));
        $this->assertSame($local->id, $autoridade->ownerId($cutinapp->id));
        $this->assertDatabaseCount('application_owners', 1);
    }

    public function test_bootstrap_de_aplicacao_sem_owner_e_idempotente(): void
    {
        $externo = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL);
        $terceiro = $this->usuario('terceiro-bootstrap-w03@example.test');
        $autoridade = app(ApplicationOwnerAuthorityService::class);

        $this->assertNull($autoridade->ownerId($externo->id));
        $this->assertTrue($autoridade->isOwner($root, $externo->id));
        $this->assertTrue($autoridade->isOwner($root, $externo->id));
        $this->assertFalse($autoridade->isOwner($terceiro, $externo->id));
        $this->assertSame($root->id, $autoridade->ownerId($externo->id));
        $this->assertDatabaseCount('application_owners', 1);
    }

    public function test_ids_invalidos_nao_geram_vinculos_de_propriedade(): void
    {
        $root = $this->usuario(ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL);
        $autoridade = app(ApplicationOwnerAuthorityService::class);

        foreach ([0, -1] as $id) {
            $this->assertFalse($autoridade->isOwner($root, $id));
            $this->assertNull($autoridade->ownerId($id));
        }

        $this->assertDatabaseCount('application_owners', 0);
    }

    /**
     * Recuperação administrativa persistida na base deve invalidar a autoridade
     * antiga sem depender de logout, cache de usuário ou nova instância.
     * Não habilita endpoint público de transferência de titularidade.
     */
    public function test_reassociacao_persistida_revoga_owner_anterior_sem_nova_sessao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $anterior = $this->usuario('owner-anterior-w03@example.test');
        $atual = $this->usuario('owner-atual-w03@example.test');
        $autoridade = app(ApplicationOwnerAuthorityService::class);
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $anterior);

        $this->assertTrue($autoridade->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($administracao->hasAccess($anterior, $cutinapp->id));
        $this->assertFalse($autoridade->isOwner($atual, $cutinapp->id));

        // Simula somente recuperação autorizada no banco, não uma rota de troca.
        $vinculo = ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail();
        $vinculo->forceFill([
            'user_id' => $atual->id,
            'bound_by_user_id' => $atual->id,
            'bound_at' => now(),
        ])->save();

        $this->assertFalse($autoridade->isOwner($anterior, $cutinapp->id));
        $this->assertFalse($administracao->hasAccess($anterior, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission(
            $anterior, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertTrue($autoridade->isOwner($atual, $cutinapp->id));
        $this->assertTrue($administracao->hasAccess($atual, $cutinapp->id));
        $this->assertTrue($administracao->hasPermission(
            $atual, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertSame($atual->id, $autoridade->ownerId($cutinapp->id));
        $this->assertDatabaseCount('application_owners', 1);
    }

    /**
     * Recuperar a titularidade de um aplicativo não pode revogar a autoridade
     * do proprietário anterior em outro aplicativo, nem concedê-la ao novo.
     * O teste usa a mesma instância dos serviços para detectar cache obsoleto.
     */
    public function test_recuperacao_de_um_app_preserva_owner_de_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $anterior = $this->usuario('owner-multiapp-w03@example.test');
        $novo = $this->usuario('owner-recuperacao-w03@example.test');
        $autoridade = app(ApplicationOwnerAuthorityService::class);
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $anterior);
        $this->vincular($rasoio->id, $anterior);

        $this->assertTrue($autoridade->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($autoridade->isOwner($anterior, $rasoio->id));

        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $novo->id,
                'bound_by_user_id' => $novo->id,
                'bound_at' => now(),
            ])->save();

        $this->assertFalse($autoridade->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($autoridade->isOwner($anterior, $rasoio->id));
        $this->assertTrue($autoridade->isOwner($novo, $cutinapp->id));
        $this->assertFalse($autoridade->isOwner($novo, $rasoio->id));

        $this->assertFalse($administracao->hasAccess($anterior, $cutinapp->id));
        $this->assertTrue($administracao->hasAccess($anterior, $rasoio->id));
        $this->assertTrue($administracao->hasAccess($novo, $cutinapp->id));
        $this->assertFalse($administracao->hasAccess($novo, $rasoio->id));
        $this->assertTrue($administracao->hasPermission(
            $anterior, $rasoio->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->hasPermission(
            $novo, $rasoio->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $this->assertSame($anterior->id, $autoridade->ownerId($rasoio->id));
        $this->assertSame($novo->id, $autoridade->ownerId($cutinapp->id));
        $this->assertFalse($administracao->isRoot($anterior));
        $this->assertFalse($administracao->isRoot($novo));
        $this->assertDatabaseCount('application_owners', 2);
    }

    /**
     * A permissão de leitura operacional não autoriza configurar nem disparar
     * workers. Os dois endpoints devem negar antes de qualquer efeito colateral.
     */
    public function test_leitor_operacional_nao_configura_nem_despacha_worker_por_http(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-operacoes-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Somente leitura operacional W03',
            'permissions' => ['operations.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $leitor->email, $perfil->id);

        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertFalse($administracao->hasPermission(
            $leitor, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($leitor),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->putJson('/api/v1/apps/cutinapp/admin/automations/workers/events_discover', [
            'enabled' => true,
            'interval_minutes' => 5,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson('/api/v1/apps/cutinapp/admin/automations/workers/events_discover/dispatch', [], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
    }

    /**
     * O app da rota prevalece sobre query e cabeçalhos enviados pelo cliente.
     * Um leitor da Cutinapp não pode consultar workers de outro aplicativo.
     */
    public function test_leitor_operacional_nao_consulta_workers_cross_app_com_app_id_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-cross-app-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura isolada W03',
            'permissions' => ['operations.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $leitor->email, $perfil->id);

        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertFalse($administracao->hasAccess($leitor, $externo->id));

        $this->getJson('/api/v1/apps/rasoio/admin/automations?app_id='.$cutinapp->id, [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($leitor),
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
    }

    private function vincular(int $applicationId, User $usuario): void
    {
        ApplicationOwner::query()->create([
            'application_id' => $applicationId,
            'user_id' => $usuario->id,
            'bootstrap_email' => ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $usuario->id,
        ]);
    }

    private function usuario(string $email): User
    {
        return User::query()->create([
            'first_name' => 'Teste',
            'last_name' => 'W03',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }
}
