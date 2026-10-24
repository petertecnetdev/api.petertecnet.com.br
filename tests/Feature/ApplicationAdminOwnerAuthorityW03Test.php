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

    /**
     * A redução do perfil deve revogar leitura operacional imediatamente,
     * mesmo com o mesmo JWT e sem invalidar outras permissões legítimas.
     * Evita reaproveitar cache de grants ao consultar o painel de workers.
     */
    public function test_retirada_de_operations_view_revoga_acesso_a_workers_com_mesmo_jwt(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-revogado-workers-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura temporária de automações W03',
            'permissions' => ['operations.view', 'dashboard.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $leitor->email, $perfil->id);
        $token = JWTAuth::fromUser($leitor);
        $cabecalhos = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];

        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));

        // A mesma sessão deve observar a revogação sem refresh ou novo login.
        $administracao->updateProfile($cutinapp->id, $perfil->id, $root, [
            'permissions' => ['dashboard.view'],
        ]);

        $this->assertTrue($administracao->hasAccess($leitor, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'dashboard.view'));

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.permissions', ['dashboard.view']);
    }

    /**
     * Revogar o vínculo delegado deve impedir a leitura de workers no mesmo JWT
     * imediatamente, sem retirar o vínculo legítimo em outro aplicativo.
     * A revogação não pode depender de expiração de token nem de cache de sessão.
     */
    public function test_revogacao_de_vinculo_operacional_nao_afeta_outro_app_com_mesmo_jwt(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-revogado-cross-app-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura operacional Cutinapp W03',
            'permissions' => ['operations.view'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Leitura operacional Rasoio W03',
            'permissions' => ['operations.view'],
        ]);

        $vinculoCutinapp = $administracao->assign(
            $cutinapp->id, $root, $leitor->email, $perfilCutinapp->id,
        );
        $administracao->assign($rasoio->id, $root, $leitor->email, $perfilRasoio->id);

        $token = JWTAuth::fromUser($leitor);

        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertTrue($administracao->hasPermission($leitor, $rasoio->id, 'operations.view'));

        $administracao->revoke($cutinapp->id, $vinculoCutinapp->id, $root);

        $this->assertFalse($administracao->hasAccess($leitor, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertTrue($administracao->hasAccess($leitor, $rasoio->id));
        $this->assertTrue($administracao->hasPermission($leitor, $rasoio->id, 'operations.view'));

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertFalse($administracao->isRoot($leitor));
    }

    /**
     * Um JWT emitido enquanto o usuário era owner não pode conservar poderes
     * de escrita operacional depois de uma reassociação persistida do owner.
     * Exercita a autorização HTTP, não somente os métodos do serviço.
     */
    public function test_troca_de_owner_revoga_mutacoes_de_workers_no_mesmo_jwt(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $anterior = $this->usuario('owner-worker-anterior-w03@example.test');
        $atual = $this->usuario('owner-worker-atual-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $anterior);
        $this->assertTrue($administracao->hasPermission(
            $anterior, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $token = JWTAuth::fromUser($anterior);
        $cabecalhos = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];

        // Simula recuperação administrativa já autorizada e persistida.
        // Não introduz endpoint público de troca de titularidade.
        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $atual->id,
                'bound_by_user_id' => $atual->id,
                'bound_at' => now(),
            ])->save();

        $this->assertFalse($administracao->hasPermission(
            $anterior, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $this->putJson('/api/v1/apps/cutinapp/admin/automations/workers/events_discover', [
            'enabled' => false,
            'interval_minutes' => 60,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson('/api/v1/apps/cutinapp/admin/automations/workers/events_discover/dispatch', [], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertSame(
            $atual->id,
            app(ApplicationOwnerAuthorityService::class)->ownerId($cutinapp->id),
        );
        $this->assertTrue($administracao->hasPermission(
            $atual, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
    }


    /**
     * Um delegado com acesso legítimo a dois apps não pode misturar históricos
     * de workers usando app_id/application_id na query. A aplicação da rota
     * é a única fonte autorizada para filtrar o dashboard no servidor.
     */
    public function test_painel_operacional_nao_mistura_historicos_de_apps_autorizados(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-multiapp-historico-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        foreach ([$cutinapp, $rasoio] as $app) {
            $perfil = $administracao->createProfile($app->id, $root, [
                'name' => 'Consulta de automações '.$app->slug,
                'permissions' => ['operations.view'],
            ]);
            $administracao->assign($app->id, $root, $leitor->email, $perfil->id);
        }

        foreach ([
            [$cutinapp->id, 'cutinapp'],
            [$rasoio->id, 'rasoio'],
        ] as [$appId, $marcador]) {
            \Illuminate\Support\Facades\DB::table('automation_worker_runs')->insert([
                'app_id' => $appId,
                'worker_key' => 'events_discover',
                'status' => 'completed',
                'result' => json_encode(['marker' => $marcador]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = JWTAuth::fromUser($leitor);

        $this->getJson(
            '/api/v1/apps/cutinapp/admin/automations?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            ['Authorization' => 'Bearer '.$token, 'X-Peter-App' => 'cutinapp'],
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.result.marker', 'cutinapp');

        $this->getJson(
            '/api/v1/apps/rasoio/admin/automations?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id,
            ['Authorization' => 'Bearer '.$token, 'X-Peter-App' => 'rasoio'],
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.result.marker', 'rasoio');

        $this->assertFalse($administracao->isRoot($leitor));
    }

    /**
     * O proprietário local pode criar perfis apenas no aplicativo da rota.
     * app_id/application_id enviados pelo cliente e o cabeçalho de outro app
     * não devem transformar sua titularidade local em autoridade global.
     */
    public function test_owner_local_nao_cria_perfil_em_outro_app_com_contexto_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerLocal = $this->usuario('owner-local-perfil-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerLocal);

        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($ownerLocal, $rasoio->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerLocal),
            'X-Peter-App' => 'cutinapp',
        ];
        $dados = [
            'name' => 'Perfil restrito W03',
            'permissions' => ['operations.view'],
        ];

        $this->postJson(
            '/api/v1/apps/rasoio/admin/profiles?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id,
            $dados,
            $cabecalhos,
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $rasoio->id,
            'slug' => 'perfil-restrito-w03',
        ]);

        // Controle positivo: o mesmo JWT ainda administra somente seu app.
        $this->postJson(
            '/api/v1/apps/cutinapp/admin/profiles?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            $dados,
            $cabecalhos,
        )
            ->assertCreated()
            ->assertJsonPath('data.application_id', $cutinapp->id);

        $this->assertDatabaseHas('application_admin_profiles', [
            'application_id' => $cutinapp->id,
            'slug' => 'perfil-restrito-w03',
        ]);
        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $rasoio->id,
            'slug' => 'perfil-restrito-w03',
        ]);
    }

    /**
     * O endpoint de contexto é consumido pelo frontend administrativo.
     * O mesmo JWT de owner local não pode obter contexto de outra aplicação
     * manipulando a query ou o cabeçalho X-Peter-App.
     *
     * Este teste valida o limite HTTP sem mascarar o gate separado que
     * exige is_root=false para proprietários locais não globais.
     */
    public function test_contexto_administrativo_do_owner_local_nao_vaza_para_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-contexto-isolado-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);
        $this->assertTrue($administracao->isOwner($local, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($local, $rasoio->id));
        $this->assertFalse($administracao->isRoot($local));

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'cutinapp',
        ];

        // O identificador da query não deve redirecionar a autoridade local.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/context?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            $cabecalhos,
        )
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.owner_user_id', $local->id);

        // A aplicação da rota é o limite de autorização, não o header/query.
        $this->getJson(
            '/api/v1/apps/rasoio/admin/context?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id,
            $cabecalhos,
        )
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
