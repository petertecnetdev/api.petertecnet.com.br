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

    /**
     * O owner de uma aplicação não pode editar ou excluir perfis de outra,
     * mesmo quando tenta forjar app_id/application_id no mesmo JWT.
     * Verifica também que o bloqueio não altera o perfil nem sua auditoria.
     */
    public function test_owner_local_nao_altera_nem_exclui_perfil_de_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-perfis-mutacao-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);

        $perfilExterno = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Perfil protegido Rasoio W03',
            'permissions' => ['operations.view'],
        ]);
        $perfilLocal = $administracao->createProfile($cutinapp->id, $local, [
            'name' => 'Perfil local W03',
            'permissions' => ['operations.view'],
        ]);
        $auditoriasExternasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $rasoio->id)
            ->count();

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'cutinapp',
        ];
        $urlExterna = '/api/v1/apps/rasoio/admin/profiles/'.$perfilExterno->id
            .'?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id;

        $this->putJson($urlExterna, ['name' => 'Perfil invadido W03'], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->deleteJson($urlExterna, [], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseHas('application_admin_profiles', [
            'id' => $perfilExterno->id,
            'application_id' => $rasoio->id,
            'name' => 'Perfil protegido Rasoio W03',
        ]);
        $this->assertSame(
            $auditoriasExternasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $rasoio->id)
                ->count(),
        );

        // Controle positivo: a operação legítima permanece restrita ao app.
        $this->putJson(
            '/api/v1/apps/cutinapp/admin/profiles/'.$perfilLocal->id,
            ['name' => 'Perfil local atualizado W03'],
            $cabecalhos,
        )
            ->assertOk()
            ->assertJsonPath('data.application_id', $cutinapp->id);

        $this->assertDatabaseHas('application_admin_profiles', [
            'id' => $perfilLocal->id,
            'application_id' => $cutinapp->id,
            'name' => 'Perfil local atualizado W03',
        ]);
        $this->assertFalse($administracao->isRoot($local));
    }

    /**
     * Um JWT emitido para o antigo proprietário não pode criar novos perfis
     * depois de uma recuperação administrativa persistida. A revogação deve
     * atingir somente o app transferido, preservando outro app de que ele
     * continua sendo proprietário, sem nova autenticação.
     */
    public function test_transferencia_de_owner_revoga_criacao_de_perfil_sem_revogar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $anterior = $this->usuario('owner-transferencia-perfil-w03@example.test');
        $atual = $this->usuario('novo-owner-transferencia-perfil-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $anterior);
        $this->vincular($rasoio->id, $anterior);

        $token = JWTAuth::fromUser($anterior);
        $this->assertTrue($administracao->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($administracao->isOwner($anterior, $rasoio->id));

        // Recuperação administrativa previamente autorizada, sem endpoint público.
        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $atual->id,
                'bound_by_user_id' => $atual->id,
                'bound_at' => now(),
            ])->save();

        $this->assertFalse($administracao->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($administracao->isOwner($anterior, $rasoio->id));
        $this->assertFalse($administracao->isRoot($anterior));

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles', [
            'name' => 'Perfil negado apos transferencia W03',
            'permissions' => ['operations.view'],
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $cutinapp->id,
            'slug' => 'perfil-negado-apos-transferencia-w03',
        ]);

        // Controle positivo com o mesmo token: não revogar outra aplicação.
        $this->postJson('/api/v1/apps/rasoio/admin/profiles', [
            'name' => 'Perfil legitimo apos transferencia W03',
            'permissions' => ['operations.view'],
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ])
            ->assertCreated()
            ->assertJsonPath('data.application_id', $rasoio->id);

        $this->assertDatabaseHas('application_admin_profiles', [
            'application_id' => $rasoio->id,
            'slug' => 'perfil-legitimo-apos-transferencia-w03',
        ]);
        $this->assertSame(
            $atual->id,
            app(ApplicationOwnerAuthorityService::class)->ownerId($cutinapp->id),
        );
    }

    /**
     * Um owner restrito à Cutinapp não pode alterar configuração nem colocar
     * jobs na fila do Rasoio ao forjar app_id/application_id. O bloqueio deve
     * ocorrer antes de persistência, enfileiramento ou execução do worker.
     */
    public function test_owner_local_nao_dispara_worker_de_outro_app_com_identificadores_forjados(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-worker-cross-app-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);
        $this->assertTrue($administracao->isOwner($local, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($local, $rasoio->id));
        $this->assertFalse($administracao->isRoot($local));

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'cutinapp',
        ];
        $baseExterna = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';
        $forjado = '?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id;

        $this->putJson($baseExterna.$forjado, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson($baseExterna.'/dispatch'.$forjado, [], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        // Controle positivo: a autorização local não deve ser revogada.
        $this->putJson(
            '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality',
            ['enabled' => true, 'interval_minutes' => 30],
            $cabecalhos,
        )
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
    }

    /**
     * A revogação de audit.view em uma aplicação deve valer no mesmo JWT,
     * sem revelar o histórico de outro app nem revogar um grant independente.
     * O app_id informado pelo cliente não substitui o contexto da rota.
     */
    public function test_revogacao_de_auditoria_preserva_acesso_a_outro_app_com_mesmo_jwt(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-revogacao-cross-app-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Auditoria Cutinapp W03',
            'permissions' => ['audit.view'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Auditoria Rasoio W03',
            'permissions' => ['audit.view'],
        ]);
        $vinculoCutinapp = $administracao->assign(
            $cutinapp->id, $root, $auditor->email, $perfilCutinapp->id,
        );
        $administracao->assign($rasoio->id, $root, $auditor->email, $perfilRasoio->id);

        $administracao->auditAction($cutinapp->id, $root, null, 'marcador_cutinapp_w03');
        $administracao->auditAction($rasoio->id, $root, null, 'marcador_rasoio_w03');

        $token = JWTAuth::fromUser($auditor);
        $cabecalhos = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];

        $auditoriaCutinapp = $this->getJson(
            '/api/v1/apps/cutinapp/admin/audit?app_id='.$rasoio->id, $cabecalhos,
        )->assertOk();
        $acoesCutinapp = collect($auditoriaCutinapp->json('data.data'))->pluck('action')->all();
        $this->assertContains('marcador_cutinapp_w03', $acoesCutinapp);
        $this->assertNotContains('marcador_rasoio_w03', $acoesCutinapp);

        $administracao->revoke($cutinapp->id, $vinculoCutinapp->id, $root);

        $this->getJson(
            '/api/v1/apps/cutinapp/admin/audit?app_id='.$rasoio->id, $cabecalhos,
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertFalse($administracao->hasPermission($auditor, $cutinapp->id, 'audit.view'));
        $this->assertTrue($administracao->hasPermission($auditor, $rasoio->id, 'audit.view'));

        $auditoriaRasoio = $this->getJson(
            '/api/v1/apps/rasoio/admin/audit?app_id='.$cutinapp->id, $cabecalhos,
        )->assertOk();
        $acoesRasoio = collect($auditoriaRasoio->json('data.data'))->pluck('action')->all();
        $this->assertContains('marcador_rasoio_w03', $acoesRasoio);
        $this->assertNotContains('marcador_cutinapp_w03', $acoesRasoio);
    }

    /**
     * IDOR em profile_id: mesmo um owner legítimo de Cutinapp não pode
     * associar a seu colaborador um perfil pertencente ao Rasoio.
     * O app da rota governa a busca do perfil; query/body não substituem
     * o vínculo persistido. Nenhum grant ou auditoria pode ser criado
     * quando o identificador do perfil pertence a outro aplicativo.
     */
    public function test_owner_local_nao_atribui_perfil_de_outro_app_por_id_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-idor-perfil-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $colaborador = $this->usuario('colaborador-idor-perfil-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);

        $perfilExterno = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Financeiro Rasoio W03',
            'permissions' => ['finance.refund'],
        ]);
        $perfilLocal = $administracao->createProfile($cutinapp->id, $local, [
            'name' => 'Consulta Cutinapp W03',
            'permissions' => ['dashboard.view'],
        ]);
        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $cutinapp->id)
            ->count();

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'rasoio',
        ];

        $this->postJson(
            '/api/v1/apps/cutinapp/admin/assignments?app_id='.$rasoio->id
                .'&application_id='.$rasoio->id,
            [
                'email' => $colaborador->email,
                'profile_id' => $perfilExterno->id,
                'app_id' => $rasoio->id,
                'application_id' => $rasoio->id,
            ],
            $cabecalhos,
        )->assertNotFound();

        $this->assertDatabaseCount('application_admin_assignments', 0);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
        $this->assertFalse($administracao->hasAccess($colaborador, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($colaborador, $cutinapp->id, 'finance.refund'));

        // Controle positivo: o mesmo JWT atribui apenas o perfil do próprio app.
        $this->postJson(
            '/api/v1/apps/cutinapp/admin/assignments',
            ['email' => $colaborador->email, 'profile_id' => $perfilLocal->id],
            $cabecalhos,
        )
            ->assertCreated()
            ->assertJsonPath('data.application_id', $cutinapp->id)
            ->assertJsonPath('data.profile_id', $perfilLocal->id);

        $this->assertDatabaseHas('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $colaborador->id,
            'profile_id' => $perfilLocal->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('application_admin_assignments', 1);
        $this->assertTrue($administracao->hasPermission($colaborador, $cutinapp->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasPermission($colaborador, $cutinapp->id, 'finance.refund'));
        $this->assertFalse($administracao->hasAccess($colaborador, $rasoio->id));
    }

    /**
     * IDOR em assignment_id: um owner local não pode revogar a atribuição
     * de outro aplicativo ao enviar o ID externo na rota da Cutinapp.
     * A tentativa não deve gerar auditoria nem revogar o grant externo.
     */
    public function test_owner_local_nao_revoga_atribuicao_de_outro_app_por_id_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $local = $this->usuario('owner-revogacao-idor-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $colaboradorLocal = $this->usuario('colaborador-local-revogacao-w03@example.test');
        $colaboradorExterno = $this->usuario('colaborador-externo-revogacao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);

        $perfilLocal = $administracao->createProfile($cutinapp->id, $local, [
            'name' => 'Leitura Cutinapp revogacao W03',
            'permissions' => ['dashboard.view'],
        ]);
        $perfilExterno = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Leitura Rasoio revogacao W03',
            'permissions' => ['dashboard.view'],
        ]);
        $vinculoLocal = $administracao->assign(
            $cutinapp->id, $local, $colaboradorLocal->email, $perfilLocal->id,
        );
        $vinculoExterno = $administracao->assign(
            $rasoio->id, $root, $colaboradorExterno->email, $perfilExterno->id,
        );

        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')->count();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'rasoio',
        ];

        $this->deleteJson(
            '/api/v1/apps/cutinapp/admin/assignments/'.$vinculoExterno->id
                .'?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            [],
            $cabecalhos,
        )->assertNotFound();

        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoExterno->id,
            'application_id' => $rasoio->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')->count(),
        );
        $this->assertTrue($administracao->hasPermission(
            $colaboradorExterno, $rasoio->id, 'dashboard.view',
        ));

        // Controle positivo: o mesmo owner pode revogar apenas o vínculo local.
        $this->deleteJson(
            '/api/v1/apps/cutinapp/admin/assignments/'.$vinculoLocal->id,
            [],
            $cabecalhos,
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoLocal->id,
            'application_id' => $cutinapp->id,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoExterno->id,
            'application_id' => $rasoio->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertFalse($administracao->hasPermission(
            $colaboradorLocal, $cutinapp->id, 'dashboard.view',
        ));
        $this->assertTrue($administracao->hasPermission(
            $colaboradorExterno, $rasoio->id, 'dashboard.view',
        ));
    }

    /**
     * Um colaborador com acesso somente de leitura não pode usar o próprio
     * JWT para trocar seu perfil por outro com privilégios financeiros.
     * A API deve negar a autoelevação antes de criar grants ou auditoria.
     */
    public function test_colaborador_de_leitura_nao_autoatribui_perfil_financeiro_por_http(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-autoelevacao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilLeitura = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura sem gestao W03',
            'permissions' => ['dashboard.view'],
        ]);
        $perfilFinanceiro = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Financeiro protegido W03',
            'permissions' => ['finance.refund'],
        ]);
        $vinculo = $administracao->assign(
            $cutinapp->id, $root, $leitor->email, $perfilLeitura->id,
        );

        $this->assertTrue($administracao->hasPermission(
            $leitor, $cutinapp->id, 'dashboard.view',
        ));
        $this->assertFalse($administracao->hasPermission(
            $leitor, $cutinapp->id, 'finance.refund',
        ));
        $this->assertFalse($administracao->isRoot($leitor));

        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $cutinapp->id)
            ->count();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($leitor),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->postJson('/api/v1/apps/cutinapp/admin/assignments', [
            'email' => $leitor->email,
            'profile_id' => $perfilFinanceiro->id,
            'app_id' => $cutinapp->id,
            'application_id' => $cutinapp->id,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseCount('application_admin_assignments', 1);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculo->id,
            'application_id' => $cutinapp->id,
            'profile_id' => $perfilLeitura->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
        $this->assertTrue($administracao->hasPermission(
            $leitor, $cutinapp->id, 'dashboard.view',
        ));
        $this->assertFalse($administracao->hasPermission(
            $leitor, $cutinapp->id, 'finance.refund',
        ));
    }

    /**
     * Um perfil legado ou importado com permissão reservada não pode transformar
     * um colaborador em Owner, mesmo que o grant tenha sido persistido no banco.
     * O backend precisa ignorar o privilégio reservado e negar mutações.
     */
    public function test_perfil_legado_contaminado_nao_concede_gestao_reservada(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->usuario('delegado-permissao-reservada-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura com dado legado W03',
            'permissions' => ['dashboard.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);

        // Simula dado legado/importado fora da validação do formulário.
        // Não concede acesso por endpoint nem altera regras de produção.
        $perfil->forceFill([
            'permissions' => [
                'dashboard.view',
                ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
            ],
        ])->save();

        $this->assertFalse($administracao->isRoot($delegado));
        $this->assertFalse($administracao->isOwner($delegado, $cutinapp->id));
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($delegado),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_root', false)
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.permissions', ['dashboard.view']);

        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $cutinapp->id)
            ->count();

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles', [
            'name' => 'Escalada indevida W03',
            'permissions' => ['finance.refund'],
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $cutinapp->id,
            'name' => 'Escalada indevida W03',
        ]);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
    }

    /**
     * O Owner de um único aplicativo não pode converter o root do ecossistema
     * em colaborador delegado, mesmo variando a capitalização do e-mail.
     * A recusa não deve criar grant nem auditoria; uma atribuição comum
     * continua disponível apenas dentro do aplicativo do Owner local.
     */
    public function test_owner_local_nao_atribui_perfil_delegado_ao_root_global(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $local = $this->usuario('owner-sem-root-atribuicao-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $colaborador = $this->usuario('colaborador-legitimo-atribuicao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $local);

        $perfil = $administracao->createProfile($cutinapp->id, $local, [
            'name' => 'Consulta sem autoridade global W03',
            'permissions' => ['dashboard.view'],
        ]);
        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $cutinapp->id)
            ->count();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($local),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->postJson('/api/v1/apps/cutinapp/admin/assignments', [
            'email' => strtoupper(ApplicationAdminService::ROOT_ADMIN_EMAIL),
            'profile_id' => $perfil->id,
            'app_id' => $cutinapp->id,
            'application_id' => $cutinapp->id,
        ], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $root->id,
        ]);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
        $this->assertTrue($administracao->isRoot($root));
        $this->assertFalse($administracao->isRoot($local));

        $vinculoLegitimo = $administracao->assign(
            $cutinapp->id, $local, $colaborador->email, $perfil->id,
        );
        $this->assertSame($colaborador->id, $vinculoLegitimo->user_id);
        $this->assertTrue($administracao->hasPermission(
            $colaborador, $cutinapp->id, 'dashboard.view',
        ));
    }

    /**
     * A permissão operations.view é apenas de leitura operacional. Um
     * colaborador que consulta workers não pode enumerar os perfis e grants
     * administrativos (inclusive rotas legadas de RBAC) do mesmo aplicativo.
     * Protege a separação de privilégios consumida pelo Admin Center W4.
     */
    public function test_leitor_de_workers_nao_enumera_perfis_e_grants_administrativos(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $leitor = $this->usuario('leitor-workers-sem-rbac-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Operacoes somente leitura W03',
            'permissions' => ['operations.view'],
        ]);
        $vinculo = $administracao->assign(
            $cutinapp->id, $root, $leitor->email, $perfil->id,
        );

        $this->assertTrue($administracao->hasPermission($leitor, $cutinapp->id, 'operations.view'));
        $this->assertFalse($administracao->hasPermission(
            $leitor, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($leitor),
            'X-Peter-App' => 'cutinapp',
        ];

        // Controle positivo: leitura operacional autorizada.
        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $cabecalhos)
            ->assertOk();

        // Não confundir operations.view com admin.access.manage.
        foreach (['profiles', 'assignments', 'role-profiles', 'role-assignments'] as $recurso) {
            $this->getJson('/api/v1/apps/cutinapp/admin/'.$recurso, $cabecalhos)
                ->assertForbidden()
                ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        }

        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculo->id,
            'application_id' => $cutinapp->id,
            'profile_id' => $perfil->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertFalse($administracao->isRoot($leitor));
        $this->assertFalse($administracao->isOwner($leitor, $cutinapp->id));
    }

    /**
     * O contexto HTTP consumido pelo Admin Center deve refletir imediatamente
     * a troca persistida de titularidade, inclusive com JWT emitido antes dela.
     * A revogação local não pode retirar a titularidade de outro aplicativo.
     */
    public function test_transferencia_de_owner_revoga_contexto_http_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $anterior = $this->usuario('owner-contexto-revogado-w03@example.test');
        $novo = $this->usuario('novo-owner-contexto-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $anterior);
        $this->vincular($rasoio->id, $anterior);

        $tokenAntigo = JWTAuth::fromUser($anterior);
        $cabecalhosCutinapp = [
            'Authorization' => 'Bearer '.$tokenAntigo,
            'X-Peter-App' => 'cutinapp',
        ];
        $cabecalhosRasoio = [
            'Authorization' => 'Bearer '.$tokenAntigo,
            'X-Peter-App' => 'rasoio',
        ];

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhosCutinapp)
            ->assertOk()
            ->assertJsonPath('data.is_owner', true);

        // Reassociação administrativa de fixture; não cria endpoint público.
        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $novo->id,
                'bound_by_user_id' => $novo->id,
                'bound_at' => now(),
            ])->save();

        $this->assertFalse($administracao->isOwner($anterior, $cutinapp->id));
        $this->assertTrue($administracao->isOwner($novo, $cutinapp->id));
        $this->assertFalse($administracao->isRoot($novo));

        // O JWT antigo não conserva o painel administrativo da Cutinapp.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/context?app_id='.$rasoio->id,
            $cabecalhosCutinapp,
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        // O mesmo JWT ainda possui autoridade legítima no outro aplicativo.
        $this->getJson('/api/v1/apps/rasoio/admin/context', $cabecalhosRasoio)
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.owner_user_id', $anterior->id);

        $this->getJson('/api/v1/apps/cutinapp/admin/context', [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($novo),
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.owner_user_id', $novo->id);
    }

    /**
     * A permissão de auditoria não é autorização operacional. Mesmo quando
     * o delegado pode consultar trilhas administrativas, ele não pode listar,
     * reconfigurar ou disparar workers (inclusive com app_id forjado).
     * Mantém o princípio de menor privilégio sem alterar events:discover.
     */
    public function test_auditor_nao_recebe_poderes_operacionais_sobre_workers(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-sem-operacoes-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Auditoria sem operações W03',
            'permissions' => ['audit.view'],
        ]);
        $vinculo = $administracao->assign(
            $cutinapp->id, $root, $auditor->email, $perfil->id,
        );

        $this->assertTrue($administracao->hasPermission($auditor, $cutinapp->id, 'audit.view'));
        $this->assertFalse($administracao->hasPermission($auditor, $cutinapp->id, 'operations.view'));
        $this->assertFalse($administracao->hasPermission(
            $auditor, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        \Illuminate\Support\Facades\Queue::fake();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($auditor),
            'X-Peter-App' => 'rasoio',
        ];

        // Controle positivo: a auditoria do próprio aplicativo segue acessível.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/audit?app_id='.$rasoio->id,
            $cabecalhos,
        )->assertOk();

        // O cabeçalho e a query não promovem audit.view a operações.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/automations?app_id='.$rasoio->id,
            $cabecalhos,
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->putJson('/api/v1/apps/cutinapp/admin/automations/workers/events_discover', [
            'enabled' => true,
            'interval_minutes' => 5,
            'app_id' => $rasoio->id,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson(
            '/api/v1/apps/cutinapp/admin/automations/workers/events_discover/dispatch',
            ['app_id' => $rasoio->id],
            $cabecalhos,
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculo->id,
            'application_id' => $cutinapp->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
        $this->assertFalse($administracao->isRoot($auditor));
        $this->assertFalse($administracao->isOwner($auditor, $cutinapp->id));
    }

    /**
     * A leitura de auditoria deve ser isolada pela aplicação da rota, mesmo
     * quando o mesmo delegado tem audit.view em dois aplicativos e tenta
     * forjar app_id, application_id e X-Peter-App. O retorno paginado não
     * pode incluir ações do outro tenant.
     */
    public function test_auditoria_multiapp_nao_expoe_registros_de_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-multiapp-isolado-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        foreach ([$cutinapp, $rasoio] as $app) {
            $perfil = $administracao->createProfile($app->id, $root, [
                'name' => 'Auditoria isolada '.$app->slug,
                'permissions' => ['audit.view'],
            ]);
            $administracao->assign($app->id, $root, $auditor->email, $perfil->id);
        }

        $administracao->auditAction($cutinapp->id, $root, null, 'w03_auditoria_cutinapp');
        $administracao->auditAction($rasoio->id, $root, null, 'w03_auditoria_rasoio');

        $this->assertTrue($administracao->hasPermission($auditor, $cutinapp->id, 'audit.view'));
        $this->assertTrue($administracao->hasPermission($auditor, $rasoio->id, 'audit.view'));

        $token = JWTAuth::fromUser($auditor);

        foreach ([
            [$cutinapp, $rasoio, 'w03_auditoria_cutinapp', 'w03_auditoria_rasoio'],
            [$rasoio, $cutinapp, 'w03_auditoria_rasoio', 'w03_auditoria_cutinapp'],
        ] as [$appAutorizado, $appForjado, $acaoEsperada, $acaoProibida]) {
            $resposta = $this->getJson(
                '/api/v1/apps/'.$appAutorizado->slug.'/admin/audit'
                .'?app_id='.$appForjado->id.'&application_id='.$appForjado->id.'&per_page=100',
                [
                    'Authorization' => 'Bearer '.$token,
                    'X-Peter-App' => $appForjado->slug,
                ],
            )->assertOk();

            $registros = $resposta->json('data.data');
            $this->assertIsArray($registros);
            $this->assertNotEmpty($registros);
            $this->assertContains($acaoEsperada, array_column($registros, 'action'));
            $this->assertNotContains($acaoProibida, array_column($registros, 'action'));

            foreach ($registros as $registro) {
                $this->assertSame($appAutorizado->id, (int) $registro['application_id']);
            }

            $this->assertSame(
                \App\Models\ApplicationAdminAudit::query()
                    ->where('application_id', $appAutorizado->id)
                    ->count(),
                (int) $resposta->json('data.total'),
            );
        }

        $this->assertFalse($administracao->isRoot($auditor));
    }

    /**
     * Reduzir permissões de um perfil delegado deve invalidar imediatamente
     * o acesso HTTP à auditoria, mesmo reutilizando o JWT anterior.
     * A alteração local não pode retirar a permissão em outro aplicativo.
     */
    public function test_retirada_de_audit_view_revoga_leitura_http_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-revogacao-perfil-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Auditoria revogavel Cutinapp W03',
            'permissions' => ['audit.view'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Auditoria preservada Rasoio W03',
            'permissions' => ['audit.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $auditor->email, $perfilCutinapp->id);
        $administracao->assign($rasoio->id, $root, $auditor->email, $perfilRasoio->id);

        $token = JWTAuth::fromUser($auditor);
        $cabecalhos = ['Authorization' => 'Bearer '.$token];

        // Controle positivo: ambos os aplicativos permitem a leitura inicialmente.
        $this->getJson('/api/v1/apps/cutinapp/admin/audit', $cabecalhos)->assertOk();
        $this->getJson('/api/v1/apps/rasoio/admin/audit', $cabecalhos)->assertOk();

        $administracao->updateProfile($cutinapp->id, $perfilCutinapp->id, $root, [
            'permissions' => ['dashboard.view'],
        ]);

        $this->assertFalse($administracao->hasPermission($auditor, $cutinapp->id, 'audit.view'));
        $this->assertTrue($administracao->hasPermission($auditor, $cutinapp->id, 'dashboard.view'));
        $this->assertTrue($administracao->hasPermission($auditor, $rasoio->id, 'audit.view'));

        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();

        // A query e o cabeçalho não restauram uma permissão revogada.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/audit?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            [
                'Authorization' => 'Bearer '.$token,
                'X-Peter-App' => 'rasoio',
            ],
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        // O mesmo JWT mantém o acesso autorizado no outro tenant.
        $this->getJson('/api/v1/apps/rasoio/admin/audit', [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])->assertOk();

        $this->assertSame(
            $auditoriasAntes,
            \App\Models\ApplicationAdminAudit::query()->count(),
        );
        $this->assertFalse($administracao->isRoot($auditor));
        $this->assertFalse($administracao->isOwner($auditor, $cutinapp->id));
    }

    /**
     * Revogar uma atribuição deve retirar imediatamente acesso e permissões
     * do delegado, inclusive com JWT antigo e contexto de tenant forjado.
     * A mesma identidade pode continuar autorizada em outro aplicativo.
     */
    public function test_revogacao_de_atribuicao_remove_acesso_com_jwt_antigo_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerLocal = $this->usuario('owner-revogacao-efetiva-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-revogacao-efetiva-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerLocal);

        $perfilLocal = $administracao->createProfile($cutinapp->id, $ownerLocal, [
            'name' => 'Auditoria revogavel por atribuicao W03',
            'permissions' => ['audit.view'],
        ]);
        $perfilExterno = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Auditoria preservada apos revogacao W03',
            'permissions' => ['audit.view'],
        ]);
        $vinculoLocal = $administracao->assign(
            $cutinapp->id, $ownerLocal, $auditor->email, $perfilLocal->id,
        );
        $vinculoExterno = $administracao->assign(
            $rasoio->id, $root, $auditor->email, $perfilExterno->id,
        );

        $tokenAntigo = JWTAuth::fromUser($auditor);
        $cabecalhosAuditor = ['Authorization' => 'Bearer '.$tokenAntigo];

        $this->getJson('/api/v1/apps/cutinapp/admin/audit', $cabecalhosAuditor)->assertOk();
        $this->getJson('/api/v1/apps/rasoio/admin/audit', $cabecalhosAuditor)->assertOk();

        // Revogação real pela rota pública autorizada do proprietário local.
        $this->deleteJson(
            '/api/v1/apps/cutinapp/admin/assignments/'.$vinculoLocal->id,
            [],
            ['Authorization' => 'Bearer '.JWTAuth::fromUser($ownerLocal)],
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoLocal->id,
            'application_id' => $cutinapp->id,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoExterno->id,
            'application_id' => $rasoio->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertFalse($administracao->hasAccess($auditor, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($auditor, $cutinapp->id, 'audit.view'));
        $this->assertTrue($administracao->hasPermission($auditor, $rasoio->id, 'audit.view'));

        $auditoriasAposRevogacao = \App\Models\ApplicationAdminAudit::query()->count();

        // app_id, application_id e cabeçalho não ressuscitam a atribuição.
        $this->getJson(
            '/api/v1/apps/cutinapp/admin/audit?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            [
                'Authorization' => 'Bearer '.$tokenAntigo,
                'X-Peter-App' => 'rasoio',
            ],
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhosAuditor)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        // O mesmo JWT conserva somente o grant legítimo do outro tenant.
        $this->getJson('/api/v1/apps/rasoio/admin/audit', [
            'Authorization' => 'Bearer '.$tokenAntigo,
            'X-Peter-App' => 'cutinapp',
        ])->assertOk();

        $this->assertSame(
            $auditoriasAposRevogacao,
            \App\Models\ApplicationAdminAudit::query()->count(),
        );
        $this->assertFalse($administracao->isRoot($auditor));
        $this->assertFalse($administracao->isOwner($auditor, $cutinapp->id));
    }

    /**
     * Excluir um perfil com delegação ativa não pode retirar acesso de forma
     * silenciosa: primeiro é necessário revogar o vínculo explicitamente.
     * Após a revogação, o perfil pode ser excluído sem grants residuais.
     */
    public function test_exclusao_de_perfil_ativo_exige_revogacao_previa(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $proprietario = $this->usuario('owner-exclusao-segura-w03@example.test');
        $colaborador = $this->usuario('colaborador-exclusao-segura-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $proprietario);

        $perfil = $administracao->createProfile($cutinapp->id, $proprietario, [
            'name' => 'Auditoria protegida contra exclusao W03',
            'permissions' => ['audit.view'],
        ]);
        $atribuicao = $administracao->assign(
            $cutinapp->id,
            $proprietario,
            $colaborador->email,
            $perfil->id,
        );
        $this->assertTrue($administracao->hasPermission($colaborador, $cutinapp->id, 'audit.view'));
        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();

        try {
            $administracao->deleteProfile($cutinapp->id, $perfil->id, $proprietario);
            $this->fail('Um perfil com atribuição ativa não pode ser excluído.');
        } catch (\Illuminate\Validation\ValidationException $erro) {
            $this->assertArrayHasKey('profile', $erro->errors());
        }

        $this->assertDatabaseHas('application_admin_profiles', [
            'id' => $perfil->id,
            'application_id' => $cutinapp->id,
        ]);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $atribuicao->id,
            'application_id' => $cutinapp->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertSame($auditoriasAntes, \App\Models\ApplicationAdminAudit::query()->count());
        $this->assertTrue($administracao->hasPermission($colaborador, $cutinapp->id, 'audit.view'));

        $administracao->revoke($cutinapp->id, $atribuicao->id, $proprietario);
        $this->assertFalse($administracao->hasAccess($colaborador, $cutinapp->id));
        $administracao->deleteProfile($cutinapp->id, $perfil->id, $proprietario);

        $this->assertDatabaseMissing('application_admin_profiles', ['id' => $perfil->id]);
        $this->assertDatabaseMissing('application_admin_assignments', ['id' => $atribuicao->id]);
        $this->assertFalse($administracao->hasPermission($colaborador, $cutinapp->id, 'audit.view'));
        $this->assertFalse($administracao->isRoot($colaborador));
    }

    /**
     * O proprietário de uma aplicação não pode atribuir a si mesmo um perfil
     * delegado. Isso preserva a identidade Owner e impede grants redundantes
     * que poderiam sobreviver a uma futura transferência de titularidade.
     * A recusa não deve produzir vínculo ou auditoria de concessão.
     */
    public function test_owner_local_nao_cria_atribuicao_delegada_para_si_mesmo(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $proprietario = $this->usuario('owner-sem-autodelegacao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $proprietario);

        $perfil = $administracao->createProfile($cutinapp->id, $proprietario, [
            'name' => 'Consulta sem autodelegacao W03',
            'permissions' => ['dashboard.view'],
        ]);
        $auditoriasAntes = \Illuminate\Support\Facades\DB::table('application_admin_audits')
            ->where('application_id', $cutinapp->id)
            ->count();

        $this->postJson('/api/v1/apps/cutinapp/admin/assignments', [
            'email' => strtoupper($proprietario->email),
            'profile_id' => $perfil->id,
        ], [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($proprietario),
            'X-Peter-App' => 'cutinapp',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $proprietario->id,
        ]);
        $this->assertSame(
            $auditoriasAntes,
            \Illuminate\Support\Facades\DB::table('application_admin_audits')
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
        $this->assertTrue($administracao->isOwner($proprietario, $cutinapp->id));
        $this->assertTrue($administracao->hasPermission(
            $proprietario, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->isRoot($proprietario));
    }

    /**
     * Reativar um colaborador após revogação exige uma nova concessão explícita.
     * O vínculo é reutilizado sem duplicar grants, e permissões do perfil
     * anterior não sobrevivem à troca, inclusive com o mesmo JWT.
     */
    public function test_reconcessao_explicita_reusa_vinculo_revogado_sem_ressuscitar_permissoes(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $colaborador = $this->usuario('reconcessao-segura-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilAuditoria = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Auditoria temporaria reconcessao W03',
            'permissions' => ['audit.view'],
        ]);
        $perfilPainel = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Painel apos reconcessao W03',
            'permissions' => ['dashboard.view'],
        ]);

        $vinculoOriginal = $administracao->assign(
            $cutinapp->id, $root, $colaborador->email, $perfilAuditoria->id,
        );
        $cabecalhos = ['Authorization' => 'Bearer '.JWTAuth::fromUser($colaborador)];

        $this->getJson('/api/v1/apps/cutinapp/admin/audit', $cabecalhos)->assertOk();

        $administracao->revoke($cutinapp->id, $vinculoOriginal->id, $root);
        $this->assertFalse($administracao->hasAccess($colaborador, $cutinapp->id));
        $this->getJson('/api/v1/apps/cutinapp/admin/audit', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $vinculoRestaurado = $administracao->assign(
            $cutinapp->id, $root, $colaborador->email, $perfilPainel->id,
        );

        $this->assertSame($vinculoOriginal->id, $vinculoRestaurado->id);
        $this->assertDatabaseCount('application_admin_assignments', 1);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculoOriginal->id,
            'application_id' => $cutinapp->id,
            'profile_id' => $perfilPainel->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertFalse($administracao->hasPermission($colaborador, $cutinapp->id, 'audit.view'));
        $this->assertTrue($administracao->hasPermission($colaborador, $cutinapp->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasPermission(
            $colaborador, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $this->getJson('/api/v1/apps/cutinapp/admin/audit', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.permissions', ['dashboard.view'])
            ->assertJsonPath('data.is_root', false)
            ->assertJsonPath('data.is_owner', false);

        $this->assertSame(2, \App\Models\ApplicationAdminAudit::query()
            ->where('application_id', $cutinapp->id)
            ->where('action', 'admin_access_granted')
            ->count());
        $this->assertSame(1, \App\Models\ApplicationAdminAudit::query()
            ->where('application_id', $cutinapp->id)
            ->where('action', 'admin_access_revoked')
            ->count());
    }

    /**
     * O catálogo de permissões delegáveis não pode aceitar admin.access.manage
     * por POST ou PUT. Uma tentativa inválida não pode persistir perfis,
     * ampliar grants existentes nem produzir auditoria de alteração.
     */
    public function test_permissao_reservada_nao_pode_ser_injetada_em_perfil_por_http(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $proprietario = $this->usuario('owner-permissao-reservada-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $proprietario);
        $perfil = $administracao->createProfile($cutinapp->id, $proprietario, [
            'name' => 'Leitura delegavel W03',
            'permissions' => ['dashboard.view'],
        ]);

        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()
            ->where('application_id', $cutinapp->id)
            ->count();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($proprietario),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles', [
            'name' => 'Escalada indevida W03',
            'permissions' => [ApplicationAdminService::ACCESS_MANAGE_PERMISSION],
        ], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');

        $this->putJson('/api/v1/apps/cutinapp/admin/profiles/'.$perfil->id, [
            'permissions' => ['dashboard.view', ApplicationAdminService::ACCESS_MANAGE_PERMISSION],
        ], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');

        $this->assertDatabaseCount('application_admin_profiles', 1);
        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $cutinapp->id,
            'name' => 'Escalada indevida W03',
        ]);
        $this->assertSame(['dashboard.view'], $perfil->fresh()->permissions);
        $this->assertDatabaseCount('application_admin_assignments', 0);
        $this->assertSame(
            $auditoriasAntes,
            \App\Models\ApplicationAdminAudit::query()
                ->where('application_id', $cutinapp->id)
                ->count(),
        );
        $this->assertFalse($administracao->isRoot($proprietario));
    }

    /**
     * Uma edição do perfil compartilhado deve revogar finance.refund de todos
     * os delegados imediatamente, inclusive com JWT já emitido. A alteração
     * não pode retirar a permissão legítima do mesmo usuário em outro app.
     */
    public function test_alteracao_de_perfil_compartilhado_revoga_reembolso_para_todos_os_delegados(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-perfil-compartilhado-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-perfil-compartilhado-rasoio-w03@example.test');
        $primeiro = $this->usuario('delegado-financeiro-primeiro-w03@example.test');
        $segundo = $this->usuario('delegado-financeiro-segundo-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $ownerCutinapp, [
            'name' => 'Financeiro compartilhado W03',
            'permissions' => ['finance.view', 'finance.refund'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $ownerRasoio, [
            'name' => 'Reembolso Rasoio W03',
            'permissions' => ['finance.refund'],
        ]);

        $administracao->assign($cutinapp->id, $ownerCutinapp, $primeiro->email, $perfilCutinapp->id);
        $administracao->assign($cutinapp->id, $ownerCutinapp, $segundo->email, $perfilCutinapp->id);
        $administracao->assign($rasoio->id, $ownerRasoio, $primeiro->email, $perfilRasoio->id);

        $jwtPrimeiro = JWTAuth::fromUser($primeiro);
        $jwtSegundo = JWTAuth::fromUser($segundo);

        foreach ([$primeiro, $segundo] as $delegado) {
            $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'finance.refund'));
        }

        $administracao->updateProfile($cutinapp->id, $perfilCutinapp->id, $ownerCutinapp, [
            'permissions' => ['finance.view'],
        ]);

        foreach ([[$primeiro, $jwtPrimeiro], [$segundo, $jwtSegundo]] as [$delegado, $jwt]) {
            $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'finance.refund'));
            $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'finance.view'));
            $this->getJson('/api/v1/apps/cutinapp/admin/context', [
                'Authorization' => 'Bearer '.$jwt,
                'X-Peter-App' => 'cutinapp',
            ])
                ->assertOk()
                ->assertJsonPath('data.permissions', ['finance.view'])
                ->assertJsonPath('data.is_root', false)
                ->assertJsonPath('data.is_owner', false);
        }

        $this->assertTrue($administracao->hasPermission($primeiro, $rasoio->id, 'finance.refund'));
        $this->getJson('/api/v1/apps/rasoio/admin/context', [
            'Authorization' => 'Bearer '.$jwtPrimeiro,
            'X-Peter-App' => 'rasoio',
        ])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['finance.refund']);

        $this->assertDatabaseCount('application_admin_assignments', 3);
        $this->assertSame(['finance.view'], $perfilCutinapp->fresh()->permissions);
        $this->assertSame(1, \App\Models\ApplicationAdminAudit::query()
            ->where('application_id', $cutinapp->id)
            ->where('action', 'admin_profile_updated')
            ->count());
    }

    /**
     * Repetir a mesma concessão administrativa (inclusive com capitalização
     * diferente do e-mail) não pode criar grants duplicados ou alcançar outro
     * aplicativo. O JWT do colaborador permanece limitado ao perfil delegado.
     * Protege retentativas de UI/rede sem alterar a semântica de auditoria.
     */
    public function test_atribuicao_ativa_repetida_e_idempotente_por_aplicacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $proprietario = $this->usuario('owner-retentativa-w03@example.test');
        $colaborador = $this->usuario('colaborador-retentativa-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $proprietario);
        $perfil = $administracao->createProfile($cutinapp->id, $proprietario, [
            'name' => 'Consulta por retentativa W03',
            'permissions' => ['dashboard.view'],
        ]);
        $token = JWTAuth::fromUser($colaborador);
        $cabecalhosOwner = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($proprietario),
            'X-Peter-App' => 'rasoio',
        ];
        $url = '/api/v1/apps/cutinapp/admin/assignments';
        $dados = [
            'email' => $colaborador->email,
            'profile_id' => $perfil->id,
        ];

        $primeira = $this->postJson($url, $dados, $cabecalhosOwner)
            ->assertCreated()
            ->assertJsonPath('data.application_id', $cutinapp->id)
            ->assertJsonPath('data.profile_id', $perfil->id);
        $idOriginal = $primeira->json('data.id');

        $this->postJson($url.'?app_id='.$rasoio->id, [
            'email' => strtoupper($colaborador->email),
            'profile_id' => $perfil->id,
            'application_id' => $rasoio->id,
        ], $cabecalhosOwner)
            ->assertCreated()
            ->assertJsonPath('data.id', $idOriginal)
            ->assertJsonPath('data.application_id', $cutinapp->id)
            ->assertJsonPath('data.profile_id', $perfil->id);

        $this->assertDatabaseCount('application_admin_assignments', 1);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $idOriginal,
            'application_id' => $cutinapp->id,
            'user_id' => $colaborador->id,
            'profile_id' => $perfil->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $this->assertFalse($administracao->isRoot($colaborador));
        $this->assertFalse($administracao->isOwner($colaborador, $cutinapp->id));
        $this->assertTrue($administracao->hasPermission($colaborador, $cutinapp->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasPermission($colaborador, $cutinapp->id, 'finance.refund'));
        $this->assertFalse($administracao->hasAccess($colaborador, $rasoio->id));

        $this->getJson('/api/v1/apps/cutinapp/admin/context', [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['dashboard.view'])
            ->assertJsonPath('data.is_root', false)
            ->assertJsonPath('data.is_owner', false);
        $this->getJson('/api/v1/apps/rasoio/admin/context', [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])->assertForbidden()->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
    }

    /**
     * A paginação da auditoria deve manter o escopo da rota em todas as páginas,
     * mesmo quando a mesma identidade possui acesso legítimo a dois apps.
     * Query e cabeçalho forjados não podem alterar itens nem contagem total.
     */
    public function test_paginacao_de_auditoria_isola_aplicacoes_em_todas_as_paginas(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $auditor = $this->usuario('auditor-paginacao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        foreach ([$cutinapp, $rasoio] as $app) {
            $perfil = $administracao->createProfile($app->id, $root, [
                'name' => 'Paginacao de auditoria '.$app->slug,
                'permissions' => ['audit.view'],
            ]);
            $administracao->assign($app->id, $root, $auditor->email, $perfil->id);
            for ($indice = 1; $indice <= 5; $indice++) {
                $administracao->auditAction(
                    $app->id, $root, null, 'w03_paginacao_'.$app->slug.'_'.$indice,
                );
            }
        }

        $token = JWTAuth::fromUser($auditor);
        foreach ([[$cutinapp, $rasoio], [$rasoio, $cutinapp]] as [$app, $forjado]) {
            $total = \App\Models\ApplicationAdminAudit::query()
                ->where('application_id', $app->id)->count();
            $idsVistos = [];

            foreach ([1, 2, 3, 4] as $pagina) {
                $resposta = $this->getJson(
                    '/api/v1/apps/'.$app->slug.'/admin/audit'
                    .'?page='.$pagina.'&per_page=2'
                    .'&app_id='.$forjado->id.'&application_id='.$forjado->id,
                    [
                        'Authorization' => 'Bearer '.$token,
                        'X-Peter-App' => $forjado->slug,
                    ],
                )->assertOk()
                    ->assertJsonPath('data.current_page', $pagina)
                    ->assertJsonPath('data.per_page', 2)
                    ->assertJsonPath('data.total', $total);

                $registros = $resposta->json('data.data');
                $this->assertCount($pagina <= 3 ? 2 : 1, $registros);
                foreach ($registros as $registro) {
                    $this->assertSame($app->id, (int) $registro['application_id']);
                    $this->assertNotContains($registro['id'], $idsVistos);
                    $idsVistos[] = $registro['id'];
                }
            }

            $this->assertCount($total, $idsVistos);
            $this->getJson('/api/v1/apps/'.$app->slug.'/admin/audit?page=99&per_page=2', [
                'Authorization' => 'Bearer '.$token,
            ])
                ->assertOk()
                ->assertJsonPath('data.total', $total)
                ->assertJsonPath('data.data', []);
        }

        $this->assertFalse($administracao->isRoot($auditor));
    }

    /**
     * Ter autoridade de proprietário na Cutinapp e leitura operacional no
     * Rasoio não permite promover o grant delegado do Rasoio a escrita.
     * Impede confusão de contexto entre apps quando o mesmo JWT tem papéis
     * diferentes; não deve criar controles nem enfileirar jobs no outro app.
     */
    public function test_owner_de_um_app_com_grant_de_leitura_em_outro_nao_muta_workers(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerLocal = $this->usuario('owner-misto-workers-w03@example.test');
        $root = $this->usuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerLocal);
        $perfil = $administracao->createProfile($rasoio->id, $root, [
            'name' => 'Consulta de workers Rasoio W03',
            'permissions' => ['operations.view'],
        ]);
        $administracao->assign($rasoio->id, $root, $ownerLocal->email, $perfil->id);

        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($ownerLocal, $rasoio->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));
        $this->assertTrue($administracao->hasPermission($ownerLocal, $rasoio->id, 'operations.view'));
        $this->assertFalse($administracao->hasPermission(
            $ownerLocal, $rasoio->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();
        $token = JWTAuth::fromUser($ownerLocal);
        $cabecalhos = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];
        $urlExterna = '/api/v1/apps/rasoio/admin/automations';
        $forjado = '?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id;

        // Controle positivo: o grant de leitura do Rasoio permanece válido.
        $this->getJson($urlExterna.$forjado, $cabecalhos)->assertOk();

        $this->putJson($urlExterna.'/workers/discovery_quality'.$forjado, [
            'enabled' => true,
            'interval_minutes' => 30,
            'app_id' => $cutinapp->id,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson($urlExterna.'/workers/discovery_quality/dispatch'.$forjado, [
            'application_id' => $cutinapp->id,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        // Controle positivo: a titularidade da Cutinapp não foi revogada.
        $this->putJson('/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality', [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
    }

    /**
     * Um proprietário local não pode enumerar perfis ou atribuições de outra
     * aplicação. Mesmo com app_id/application_id e X-Peter-App forjados,
     * a rota determina o tenant; a leitura cruzada deve falhar sem vazar dados.
     */
    public function test_listagens_de_perfis_e_atribuicoes_respeitam_escopo_da_rota(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-listagem-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-listagem-rasoio-w03@example.test');
        $delegadoCutinapp = $this->usuario('delegado-listagem-cutinapp-w03@example.test');
        $delegadoRasoio = $this->usuario('delegado-listagem-rasoio-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $ownerCutinapp, [
            'name' => 'Consulta privada Cutinapp W03',
            'permissions' => ['dashboard.view'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $ownerRasoio, [
            'name' => 'Financeiro privado Rasoio W03',
            'permissions' => ['finance.view'],
        ]);
        $vinculoCutinapp = $administracao->assign(
            $cutinapp->id, $ownerCutinapp, $delegadoCutinapp->email, $perfilCutinapp->id,
        );
        $vinculoRasoio = $administracao->assign(
            $rasoio->id, $ownerRasoio, $delegadoRasoio->email, $perfilRasoio->id,
        );

        foreach ([
            [$cutinapp, $rasoio, $ownerCutinapp, $perfilCutinapp, $vinculoCutinapp],
            [$rasoio, $cutinapp, $ownerRasoio, $perfilRasoio, $vinculoRasoio],
        ] as [$app, $externo, $owner, $perfil, $vinculo]) {
            $cabecalhos = [
                'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
                'X-Peter-App' => $externo->slug,
            ];
            $query = '?app_id='.$externo->id.'&application_id='.$externo->id;
            $base = '/api/v1/apps/'.$app->slug.'/admin/';

            $perfis = $this->getJson($base.'profiles'.$query, $cabecalhos)
                ->assertOk()
                ->assertJsonCount(1, 'data');
            $this->assertSame([$perfil->id], array_column($perfis->json('data'), 'id'));
            $this->assertSame($app->id, (int) $perfis->json('data.0.application_id'));

            $atribuicoes = $this->getJson($base.'assignments'.$query, $cabecalhos)
                ->assertOk()
                ->assertJsonCount(1, 'data');
            $this->assertSame([$vinculo->id], array_column($atribuicoes->json('data'), 'id'));
            $this->assertSame($app->id, (int) $atribuicoes->json('data.0.application_id'));

            // Nem mesmo o owner de outro app consegue enumerar o tenant externo.
            foreach (['profiles', 'assignments'] as $recurso) {
                $this->getJson(
                    '/api/v1/apps/'.$externo->slug.'/admin/'.$recurso
                        .'?app_id='.$app->id.'&application_id='.$app->id,
                    ['Authorization' => 'Bearer '.JWTAuth::fromUser($owner), 'X-Peter-App' => $app->slug],
                )
                    ->assertForbidden()
                    ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
            }

            $this->assertFalse($administracao->isRoot($owner));
            $this->assertFalse($administracao->isOwner($owner, $externo->id));
        }

        $this->assertDatabaseCount('application_admin_profiles', 2);
        $this->assertDatabaseCount('application_admin_assignments', 2);
    }

    /**
     * O identificador de um perfil deve ser único somente dentro do app.
     * Uma tentativa de criar/renomear para slug já ocupado não pode mudar
     * permissões, dados ou auditoria, mesmo com contexto externo forjado.
     */
    public function test_slug_de_perfil_e_unico_no_app_sem_bloquear_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-slug-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-slug-rasoio-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        $principal = $administracao->createProfile($cutinapp->id, $ownerCutinapp, [
            'name' => 'Painel seguro Cutinapp W03',
            'slug' => 'painel-seguro-w03',
            'permissions' => ['dashboard.view'],
        ]);
        $secundario = $administracao->createProfile($cutinapp->id, $ownerCutinapp, [
            'name' => 'Operacao isolada Cutinapp W03',
            'slug' => 'operacao-isolada-w03',
            'permissions' => ['operations.view'],
        ]);
        $externo = $administracao->createProfile($rasoio->id, $ownerRasoio, [
            'name' => 'Painel seguro Rasoio W03',
            'slug' => 'painel-seguro-w03',
            'permissions' => ['finance.view'],
        ]);

        $this->assertSame($principal->slug, $externo->slug);
        $this->assertNotSame($principal->application_id, $externo->application_id);

        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();
        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'rasoio',
        ];
        $base = '/api/v1/apps/cutinapp/admin/profiles'
            .'?app_id='.$rasoio->id.'&application_id='.$rasoio->id;

        $this->postJson($base, [
            'name' => 'Duplicado bloqueado W03',
            'slug' => 'PAINEL SEGURO W03',
            'permissions' => ['finance.refund'],
        ], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->putJson(
            '/api/v1/apps/cutinapp/admin/profiles/'.$secundario->id
                .'?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            [
                'name' => 'Tentativa de sobrescrita W03',
                'slug' => 'painel-seguro-w03',
                'permissions' => ['finance.refund'],
            ],
            $cabecalhos,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('application_admin_profiles', 3);
        $this->assertDatabaseHas('application_admin_profiles', [
            'id' => $principal->id,
            'application_id' => $cutinapp->id,
            'slug' => 'painel-seguro-w03',
        ]);
        $this->assertDatabaseHas('application_admin_profiles', [
            'id' => $secundario->id,
            'application_id' => $cutinapp->id,
            'name' => 'Operacao isolada Cutinapp W03',
            'slug' => 'operacao-isolada-w03',
        ]);
        $this->assertSame(['operations.view'], $secundario->fresh()->permissions);
        $this->assertSame(['finance.view'], $externo->fresh()->permissions);
        $this->assertSame($auditoriasAntes, \App\Models\ApplicationAdminAudit::query()->count());
        $this->assertFalse($administracao->isRoot($ownerCutinapp));
    }

    /**
     * Nenhuma rota administrativa pode devolver dados ou executar mutações
     * sem JWT, mesmo quando a requisição forja identificadores de outro app.
     * A autenticação precede a autorização e a lógica de negócio.
     */
    public function test_rotas_administrativas_exigem_jwt_antes_de_qualquer_mutacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-auth-obrigatoria-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $owner);
        $perfil = $administracao->createProfile($cutinapp->id, $owner, [
            'name' => 'Perfil privado autenticado W03',
            'permissions' => ['dashboard.view'],
        ]);
        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();
        $cabecalhos = ['X-Peter-App' => 'cutinapp'];
        $base = '/api/v1/apps/cutinapp/admin/';
        $forjado = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;

        foreach (['context', 'profiles', 'assignments', 'audit', 'automations'] as $recurso) {
            $this->getJson($base.$recurso.$forjado, $cabecalhos)
                ->assertUnauthorized();
        }

        $this->postJson($base.'profiles'.$forjado, [
            'name' => 'Injecao sem autenticacao W03',
            'permissions' => ['finance.refund'],
        ], $cabecalhos)->assertUnauthorized();

        $this->postJson($base.'assignments'.$forjado, [
            'email' => 'intruso-auth-w03@example.test',
            'profile_id' => $perfil->id,
        ], $cabecalhos)->assertUnauthorized();

        $this->putJson($base.'automations/workers/discovery_quality'.$forjado, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhos)->assertUnauthorized();

        $this->assertDatabaseCount('application_admin_profiles', 1);
        $this->assertDatabaseCount('application_admin_assignments', 0);
        $this->assertDatabaseCount('automation_worker_controls', 0);
        $this->assertSame(
            $auditoriasAntes,
            \App\Models\ApplicationAdminAudit::query()->count(),
        );
        $this->assertSame(['dashboard.view'], $perfil->fresh()->permissions);
    }

    /**
     * Mesmo um proprietário autorizado não pode cadastrar ou disparar um worker
     * desconhecido. Com o executor global desativado, um worker conhecido e
     * configurado também não deve gerar runs nem enfileirar jobs.
     *
     * Este contrato cobre a operação W03 sem alterar o worker events:discover
     * sob ownership NP12.
     */
    public function test_worker_nao_registrado_e_dispatch_global_desativado_falham_sem_efeitos(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $owner = $this->usuario('owner-worker-failclosed-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->assertFalse(app(ApplicationAdminService::class)->isRoot($owner));

        \Illuminate\Support\Facades\Queue::fake();
        config()->set('automation_workers.enabled', false);

        $cabecalhos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'cutinapp',
        ];
        $base = '/api/v1/apps/cutinapp/admin/automations/workers/';

        $this->putJson($base.'worker_nao_registrado_w03', [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('worker');

        $this->postJson($base.'worker_nao_registrado_w03/dispatch', [], $cabecalhos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('worker');

        $this->assertDatabaseCount('automation_worker_controls', 0);
        $this->assertDatabaseCount('automation_worker_runs', 0);

        // Configuração válida não ignora a chave global de desativação.
        $this->putJson($base.'discovery_quality', [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->postJson($base.'discovery_quality/dispatch', [], $cabecalhos)
            ->assertStatus(409);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 1);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
    }

    /**
     * O disparo manual usa exclusivamente o app da rota e exige controle
     * habilitado nesse app. Requisições repetidas não podem enfileirar o
     * mesmo worker duas vezes enquanto há uma execução pendente.
     *
     * A cobertura é do controle operacional genérico, não de events:discover.
     */
    public function test_dispatch_manual_respeita_controle_por_app_e_impede_execucao_duplicada(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-dispatch-isolado-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);
        $this->assertFalse(app(ApplicationAdminService::class)->isRoot($owner));

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $token = JWTAuth::fromUser($owner);
        $cabecalhosCutinapp = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];
        $cabecalhosRasoio = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cabecalhosCutinapp)->assertOk();

        $this->putJson($workerRasoio, [
            'enabled' => false,
            'interval_minutes' => 30,
        ], $cabecalhosRasoio)->assertOk();

        // IDs forjados não substituem o app da rota nem o controle desativado.
        $forjado = '?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id;
        $this->postJson($workerRasoio.'/dispatch'.$forjado, [
            'app_id' => $cutinapp->id,
            'application_id' => $cutinapp->id,
        ], $cabecalhosRasoio)->assertStatus(409);

        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $this->postJson($workerCutinapp.'/dispatch', [], $cabecalhosCutinapp)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['run_id']]);

        // A segunda requisição não cria outro run nem outro job.
        $this->postJson($workerCutinapp.'/dispatch', [], $cabecalhosCutinapp)
            ->assertStatus(409);

        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);

        $this->getJson('/api/v1/apps/rasoio/admin/automations', $cabecalhosRasoio)
            ->assertOk()
            ->assertJsonCount(0, 'data.recent_runs');
        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $cabecalhosCutinapp)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs');
    }


    /**
     * Intervalos inválidos não podem modificar o controle já persistido.
     * O app da rota prevalece sobre IDs e cabeçalhos forjados, inclusive
     * quando a validação retorna 422. Limites válidos devem continuar aceitos.
     */
    public function test_intervalo_invalido_nao_altera_controle_de_worker_nem_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-intervalo-worker-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        \Illuminate\Support\Facades\Queue::fake();
        $token = JWTAuth::fromUser($owner);
        $cutinappHeaders = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];
        $rasoioHeaders = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ];
        $cutinappWorker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $rasoioWorker = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($cutinappWorker, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $cutinappHeaders)->assertOk();

        $this->putJson($rasoioWorker, [
            'enabled' => false,
            'interval_minutes' => 45,
        ], $rasoioHeaders)->assertOk();

        $forjado = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;
        $cabecalhosForjados = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ];

        foreach ([4, 1441, '10.5', null] as $intervaloInvalido) {
            $this->putJson($cutinappWorker.$forjado, [
                'enabled' => false,
                'interval_minutes' => $intervaloInvalido,
            ], $cabecalhosForjados)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('interval_minutes');
        }

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 45,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        foreach ([5, 1440] as $intervaloValido) {
            $this->putJson($cutinappWorker, [
                'enabled' => true,
                'interval_minutes' => $intervaloValido,
            ], $cutinappHeaders)
                ->assertOk()
                ->assertJsonPath('data.interval_minutes', $intervaloValido);
        }

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 1440,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 45,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
    }


    /**
     * Revogar um controle habilitado deve bloquear imediatamente o dispatch
     * do próprio app, inclusive com IDs externos forjados, sem desabilitar
     * o mesmo worker de outra aplicação nem criar jobs residuais.
     */
    public function test_desativacao_de_worker_revoga_dispatch_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-revogacao-worker-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $token = JWTAuth::fromUser($owner);
        $headersCutinapp = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ];
        $headersRasoio = [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headersCutinapp)->assertOk();

        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 45,
        ], $headersRasoio)->assertOk();

        $this->putJson($workerCutinapp, [
            'enabled' => false,
            'interval_minutes' => 30,
        ], $headersCutinapp)
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $forjado = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;
        $this->postJson($workerCutinapp.'/dispatch'.$forjado, [
            'app_id' => $rasoio->id,
            'application_id' => $rasoio->id,
        ], $headersRasoio)->assertStatus(409);

        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $this->postJson($workerRasoio.'/dispatch', [], $headersRasoio)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['run_id']]);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
        ]);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headersCutinapp)
            ->assertOk()
            ->assertJsonCount(0, 'data.recent_runs');
        $this->getJson('/api/v1/apps/rasoio/admin/automations', $headersRasoio)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs');
    }

    /**
     * Revogar a titularidade deve bloquear o dispatch mesmo quando o controle
     * permanece habilitado. O novo owner pode continuar a operação legítima;
     * o JWT antigo não cria runs nem jobs após a transferência persistida.
     */
    public function test_revogacao_de_owner_com_worker_habilitado_impede_dispatch_com_jwt_antigo(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $anterior = $this->usuario('owner-worker-revogado-w03@example.test');
        $sucessor = $this->usuario('owner-worker-sucessor-w03@example.test');
        $this->vincular($cutinapp->id, $anterior);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $worker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $headersAnterior = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($anterior),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersSucessor = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($sucessor),
            'X-Peter-App' => 'cutinapp',
        ];

        $this->putJson($worker, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headersAnterior)->assertOk();

        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $sucessor->id,
                'bound_by_user_id' => $sucessor->id,
                'bound_at' => now(),
            ])->save();

        $this->assertFalse(app(ApplicationAdminService::class)->hasAccess($anterior, $cutinapp->id));
        $this->assertTrue(app(ApplicationAdminService::class)->hasAccess($sucessor, $cutinapp->id));

        $this->postJson($worker.'/dispatch?app_id='.$cutinapp->id, [
            'application_id' => $cutinapp->id,
        ], $headersAnterior)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $this->postJson($worker.'/dispatch', [], $headersSucessor)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['run_id']]);

        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);
    }

    /**
     * A recuperação de titularidade em uma aplicação não pode revogar a
     * operação legítima do mesmo usuário em outra aplicação. O sucessor
     * recebe poderes apenas no app transferido, mesmo reutilizando JWTs.
     */
    public function test_transferencia_de_owner_preserva_dispatch_legitimo_em_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $anterior = $this->usuario('owner-multiapp-transferido-w03@example.test');
        $sucessor = $this->usuario('sucessor-multiapp-transferido-w03@example.test');

        $this->vincular($cutinapp->id, $anterior);
        $this->vincular($rasoio->id, $anterior);
        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headersAnterior = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($anterior),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersSucessor = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($sucessor),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersAnteriorRasoio = array_merge($headersAnterior, ['X-Peter-App' => 'rasoio']);
        $headersSucessorRasoio = array_merge($headersSucessor, ['X-Peter-App' => 'rasoio']);
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headersAnterior)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 45,
        ], $headersAnteriorRasoio)->assertOk();

        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $sucessor->id,
                'bound_by_user_id' => $sucessor->id,
                'bound_at' => now(),
            ])->save();

        $administracao = app(ApplicationAdminService::class);
        $this->assertFalse($administracao->hasAccess($anterior, $cutinapp->id));
        $this->assertTrue($administracao->hasAccess($anterior, $rasoio->id));
        $this->assertTrue($administracao->hasAccess($sucessor, $cutinapp->id));
        $this->assertFalse($administracao->hasAccess($sucessor, $rasoio->id));
        $this->assertFalse($administracao->isRoot($anterior));
        $this->assertFalse($administracao->isRoot($sucessor));

        $this->postJson($workerCutinapp.'/dispatch', [], $headersAnterior)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->postJson($workerRasoio.'/dispatch', [], $headersSucessorRasoio)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $this->postJson($workerRasoio.'/dispatch', [], $headersAnteriorRasoio)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['run_id']]);
        $this->postJson($workerCutinapp.'/dispatch', [], $headersSucessor)
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['run_id']]);

        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * Mesmo quando a identidade administra dois apps, o servidor deve
     * persistir a configuração no app da rota, nunca no app_id do cliente.
     * Esta regressão usa um PUT válido (não apenas validação de erro).
     */
    public function test_atualizacao_valida_de_worker_ignora_app_ids_forjados_mesmo_com_owner_multiapp(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-worker-payload-multiapp-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        \Illuminate\Support\Facades\Queue::fake();
        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $worker = '/admin/automations/workers/discovery_quality';

        $this->putJson('/api/v1/apps/rasoio'.$worker, [
            'enabled' => false,
            'interval_minutes' => 60,
        ], $headers)->assertOk();

        $forjado = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;
        $this->putJson('/api/v1/apps/cutinapp'.$worker.$forjado, [
            'enabled' => true,
            'interval_minutes' => 20,
            'app_id' => $rasoio->id,
            'application_id' => $rasoio->id,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.interval_minutes', 20);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 20,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 60,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);
    }


    /**
     * Falha terminal libera nova tentativa, mas uma execução pendente continua
     * bloqueando duplicatas. O histórico e a fila permanecem isolados por app.
     */
    public function test_worker_falhado_pode_ser_reexecutado_sem_duplicar_run_pendente(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-worker-retry-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'cutinapp',
        ];
        $worker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';

        $this->putJson($worker, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();

        $primeiro = $this->postJson($worker.'/dispatch', [], $headers)
            ->assertAccepted()
            ->json('data.run_id');
        $this->assertNotNull($primeiro);

        \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $primeiro)
            ->update([
                'status' => 'failed',
                'last_error' => 'RuntimeException',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $segundo = $this->postJson($worker.'/dispatch', [], $headers)
            ->assertAccepted()
            ->json('data.run_id');
        $this->assertNotSame((int) $primeiro, (int) $segundo);

        $this->postJson($worker.'/dispatch', [], $headers)->assertStatus(409);

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $primeiro,
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $segundo,
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data.recent_runs');
    }

    /**
     * O painel exibe somente as 30 execuções mais recentes. Uma execução
     * antiga ainda em andamento não pode ser ignorada pelo servidor quando
     * 31 execuções terminais posteriores a retiram dessa janela visual.
     * Protege contra duplicidade mesmo que a interface mostre Executar.
     */
    public function test_execucao_pendente_antiga_fora_do_historico_visivel_bloqueia_novo_dispatch(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-worker-historico-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();

        $pendente = \Illuminate\Support\Facades\DB::table('automation_worker_runs')->insertGetId([
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'running',
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $terminais = [];
        for ($indice = 0; $indice < 31; $indice++) {
            $terminais[] = [
                'app_id' => $cutinapp->id,
                'worker_key' => 'discovery_quality',
                'status' => 'completed',
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        \Illuminate\Support\Facades\DB::table('automation_worker_runs')->insert($terminais);

        $painel = $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
            ->assertOk()
            ->assertJsonCount(30, 'data.recent_runs');
        $idsVisiveis = array_map('intval', array_column($painel->json('data.recent_runs'), 'id'));
        $this->assertNotContains((int) $pendente, $idsVisiveis);

        // O limite do dashboard jamais pode ser utilizado como autorização.
        $this->postJson($workerCutinapp.'/dispatch', [], $headers)->assertStatus(409);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $pendente,
            'app_id' => $cutinapp->id,
            'status' => 'running',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 32);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        // Uma pendência da Cutinapp não bloqueia um app independente.
        $this->postJson($workerRasoio.'/dispatch', [], $headers)->assertAccepted();
        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 33);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);
    }

    /**
     * A identidade bootstrap/root do ecossistema não pode assumir implicitamente
     * a administração de uma aplicação já vinculada a outro proprietário.
     * O mesmo usuário continua autorizado em um segundo app sem proprietário,
     * preservando o isolamento e a inicialização legítima por aplicação.
     */
    public function test_identidade_root_nao_toma_worker_de_app_com_owner_local(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerLocal = $this->usuario('owner-root-isolamento-w03@example.test');
        $root = $this->usuario(ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL);
        $autoridade = app(ApplicationOwnerAuthorityService::class);
        $this->vincular($cutinapp->id, $ownerLocal);

        $this->assertFalse($autoridade->isOwner($root, $cutinapp->id));
        $this->assertTrue($autoridade->isOwner($root, $rasoio->id));
        $this->assertTrue(app(ApplicationAdminService::class)->isRoot($root));
        $this->assertSame($ownerLocal->id, $autoridade->ownerId($cutinapp->id));
        $this->assertSame($root->id, $autoridade->ownerId($rasoio->id));

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($root),
            // Cabeçalho conflitante não pode substituir o app da rota.
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
            ->assertForbidden();
        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
            'app_id' => $rasoio->id,
        ], $headers)->assertForbidden();
        $this->postJson($workerCutinapp.'/dispatch', [
            'application_id' => $rasoio->id,
        ], $headers)->assertForbidden();

        $this->assertDatabaseMissing('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
        ]);

        $this->getJson('/api/v1/apps/rasoio/admin/automations', $headers)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();
        $this->postJson($workerRasoio.'/dispatch', [], $headers)->assertAccepted();

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        $this->assertDatabaseCount('application_owners', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);
    }

    /**
     * A desativação do worker deve ser revalidada pelo job no momento da
     * execução, inclusive se o job já estava na fila. Uma revogação local
     * não pode cancelar nem executar trabalhos de outra aplicação.
     */
    public function test_revogacao_de_worker_enfileirado_impede_execucao_tardia_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-revogacao-fila-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $cutinappWorker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $rasoioWorker = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        foreach ([$cutinappWorker, $rasoioWorker] as $worker) {
            $this->putJson($worker, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headers)->assertOk();
        }

        $runCutinapp = (int) $this->postJson($cutinappWorker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $runRasoio = (int) $this->postJson($rasoioWorker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $this->assertNotSame($runCutinapp, $runRasoio);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runCutinapp, 'app_id' => $cutinapp->id, 'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio, 'app_id' => $rasoio->id, 'status' => 'queued',
        ]);

        // A revogação ocorre após o enfileiramento, antes do consumo.
        $this->putJson($cutinappWorker, [
            'enabled' => false,
            'interval_minutes' => 30,
            'app_id' => $rasoio->id,
            'application_id' => $rasoio->id,
        ], $headers)->assertOk()->assertJsonPath('data.enabled', false);

        (new \App\Jobs\RunAutomationWorker($runCutinapp))->handle();

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runCutinapp,
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'skipped',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio,
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
        ]);

        $this->postJson($cutinappWorker.'/dispatch', [], $headers)->assertStatus(409);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * Uma exceção do job não pode expor dados sensíveis no banco ou no
     * dashboard. A falha encerra somente a execução do app correspondente,
     * permitindo nova tentativa sem interromper o worker de outro app.
     */
    public function test_falha_de_worker_redige_erro_e_preserva_reexecucao_por_aplicacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-erro-redigido-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        foreach ([$workerCutinapp, $workerRasoio] as $worker) {
            $this->putJson($worker, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headers)->assertOk();
        }

        $falhaId = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $rasoioId = (int) $this->postJson($workerRasoio.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $segredo = 'segredo-api-nao-expor-w03';
        (new \App\Jobs\RunAutomationWorker($falhaId))
            ->failed(new \RuntimeException($segredo));

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $falhaId,
            'app_id' => $cutinapp->id,
            'status' => 'failed',
            'last_error' => \RuntimeException::class,
        ]);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'id' => $falhaId,
            'last_error' => $segredo,
        ]);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $falhaId)->value('finished_at'));

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
            ->assertOk()
            ->assertJsonPath('data.recent_runs.0.id', $falhaId)
            ->assertJsonPath('data.recent_runs.0.error_type', \RuntimeException::class)
            ->assertDontSee($segredo);

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $rasoioId,
            'app_id' => $rasoio->id,
            'status' => 'queued',
        ]);

        $novaTentativa = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $this->assertNotSame($falhaId, $novaTentativa);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $novaTentativa,
            'app_id' => $cutinapp->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 3);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 3);
    }

    /**
     * Entregas duplicadas da fila não podem ressuscitar execuções terminais
     * nem apagar o resultado ou a falha de um aplicativo. O consumidor deve
     * consultar o estado persistido antes de invocar qualquer handler.
     */
    public function test_reentrega_de_job_nao_ressuscita_execucoes_terminais_entre_apps(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-reentrega-terminal-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        foreach ([$workerCutinapp, $workerRasoio] as $worker) {
            $this->putJson($worker, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headers)->assertOk();
        }

        $sucessoId = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $falhaId = (int) $this->postJson($workerRasoio.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $this->assertNotSame($sucessoId, $falhaId);

        $terminadoEm = now()->subMinutes(3);
        \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $sucessoId)
            ->update([
                'status' => 'succeeded',
                'result' => json_encode(['origin' => 'completed-once']),
                'finished_at' => $terminadoEm,
            ]);
        \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $falhaId)
            ->update([
                'status' => 'failed',
                'last_error' => \RuntimeException::class,
                'finished_at' => $terminadoEm,
            ]);

        $sucessoAntes = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $sucessoId)->first();
        $falhaAntes = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $falhaId)->first();

        // Simula entrega duplicada e callback de falha atrasado do mesmo job.
        (new \App\Jobs\RunAutomationWorker($sucessoId))->handle();
        (new \App\Jobs\RunAutomationWorker($sucessoId))
            ->failed(new \RuntimeException('erro tardio nao deve alterar sucesso'));
        (new \App\Jobs\RunAutomationWorker($falhaId))->handle();

        $sucessoDepois = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $sucessoId)->first();
        $falhaDepois = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $falhaId)->first();

        $this->assertSame('succeeded', $sucessoDepois->status);
        $this->assertSame($sucessoAntes->result, $sucessoDepois->result);
        $this->assertSame($sucessoAntes->finished_at, $sucessoDepois->finished_at);
        $this->assertSame($sucessoAntes->last_error, $sucessoDepois->last_error);
        $this->assertSame('failed', $falhaDepois->status);
        $this->assertSame($falhaAntes->last_error, $falhaDepois->last_error);
        $this->assertSame($falhaAntes->finished_at, $falhaDepois->finished_at);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * A redução de um perfil operacional compartilhado deve revogar leitura
     * de workers para TODOS os delegados usando seus JWTs anteriores.
     * A mudança não pode retirar o acesso legítimo em outra aplicação.
     */
    public function test_perfil_operacional_compartilhado_revoga_painel_de_todos_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-ops-compartilhado-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-ops-compartilhado-rasoio-w03@example.test');
        $primeiro = $this->usuario('ops-compartilhado-primeiro-w03@example.test');
        $segundo = $this->usuario('ops-compartilhado-segundo-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        $perfilCutinapp = $administracao->createProfile($cutinapp->id, $ownerCutinapp, [
            'name' => 'Operadores compartilhados Cutinapp W03',
            'permissions' => ['operations.view', 'dashboard.view'],
        ]);
        $perfilRasoio = $administracao->createProfile($rasoio->id, $ownerRasoio, [
            'name' => 'Operador independente Rasoio W03',
            'permissions' => ['operations.view'],
        ]);

        $administracao->assign($cutinapp->id, $ownerCutinapp, $primeiro->email, $perfilCutinapp->id);
        $administracao->assign($cutinapp->id, $ownerCutinapp, $segundo->email, $perfilCutinapp->id);
        $administracao->assign($rasoio->id, $ownerRasoio, $primeiro->email, $perfilRasoio->id);

        $tokens = [
            [$primeiro, JWTAuth::fromUser($primeiro)],
            [$segundo, JWTAuth::fromUser($segundo)],
        ];

        foreach ($tokens as [$delegado, $token]) {
            $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'operations.view'));
            $this->getJson('/api/v1/apps/cutinapp/admin/automations', [
                'Authorization' => 'Bearer '.$token,
                'X-Peter-App' => 'cutinapp',
            ])->assertOk();
        }

        $administracao->updateProfile($cutinapp->id, $perfilCutinapp->id, $ownerCutinapp, [
            'permissions' => ['dashboard.view'],
        ]);

        foreach ($tokens as [$delegado, $token]) {
            $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'operations.view'));
            $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'dashboard.view'));

            $headers = [
                'Authorization' => 'Bearer '.$token,
                'X-Peter-App' => 'rasoio',
            ];
            $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
                ->assertForbidden()
                ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
            $this->getJson('/api/v1/apps/cutinapp/admin/context', $headers)
                ->assertOk()
                ->assertJsonPath('data.permissions', ['dashboard.view'])
                ->assertJsonPath('data.is_root', false);
        }

        $this->getJson('/api/v1/apps/rasoio/admin/automations', [
            'Authorization' => 'Bearer '.$tokens[0][1],
            'X-Peter-App' => 'cutinapp',
        ])->assertOk();

        $this->assertDatabaseCount('application_admin_assignments', 3);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        $this->assertSame(1, \App\Models\ApplicationAdminAudit::query()
            ->where('application_id', $cutinapp->id)
            ->where('action', 'admin_profile_updated')
            ->count());
    }

    /**
     * Uma execução bem-sucedida libera uma nova rodada do mesmo worker sem
     * apagar resultado, timestamps ou histórico. Pendências de outro app
     * continuam bloqueadas somente no próprio escopo.
     */
    public function test_worker_concluido_libera_nova_rodada_sem_apagar_historico_ou_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-worker-sucesso-retry-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        foreach ([$workerCutinapp, $workerRasoio] as $worker) {
            $this->putJson($worker, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headers)->assertOk();
        }

        $primeiro = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $pendenteRasoio = (int) $this->postJson($workerRasoio.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $finalizadoEm = now()->subMinute();
        $resultado = json_encode(['processed' => 3, 'source' => 'w03']);
        \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $primeiro)
            ->update([
                'status' => 'succeeded',
                'result' => $resultado,
                'finished_at' => $finalizadoEm,
                'updated_at' => $finalizadoEm,
            ]);

        $segundo = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $this->assertNotSame($primeiro, $segundo);

        // O novo run pendente impede duplicidade na Cutinapp. A pendência
        // do Rasoio é preservada sem gastar uma requisição extra sujeita ao
        // rate limit global (throttle:6,1) da rota de disparo.
        $this->postJson($workerCutinapp.'/dispatch', [], $headers)->assertStatus(409);

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $primeiro,
            'app_id' => $cutinapp->id,
            'status' => 'succeeded',
            'result' => $resultado,
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $segundo,
            'app_id' => $cutinapp->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $pendenteRasoio,
            'app_id' => $rasoio->id,
            'status' => 'queued',
        ]);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $primeiro)->value('finished_at'));
        $this->assertDatabaseCount('automation_worker_runs', 3);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 3);

        $this->getJson('/api/v1/apps/cutinapp/admin/automations', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data.recent_runs');
        $this->getJson('/api/v1/apps/rasoio/admin/automations', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs');
    }

    /**
     * A troca de proprietário com jobs já enfileirados revoga imediatamente
     * a leitura do histórico e as mutações do antigo titular. A fila já
     * criada continua íntegra e o acesso dele a outro app é preservado.
     * Cabeçalhos e app_id forjados não podem contornar o escopo da rota.
     */
    public function test_transferencia_com_runs_enfileirados_oculta_historico_do_owner_revogado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $anterior = $this->usuario('owner-fila-transferida-w03@example.test');
        $sucessor = $this->usuario('sucessor-fila-transferida-w03@example.test');
        $this->vincular($cutinapp->id, $anterior);
        $this->vincular($rasoio->id, $anterior);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $tokenAntigo = JWTAuth::fromUser($anterior);
        $headersAntigo = [
            'Authorization' => 'Bearer '.$tokenAntigo,
            'X-Peter-App' => 'rasoio',
        ];
        $headersNovo = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($sucessor),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        foreach ([$workerCutinapp, $workerRasoio] as $worker) {
            $this->putJson($worker, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headersAntigo)->assertOk();
        }

        $runCutinapp = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headersAntigo)
            ->assertAccepted()->json('data.run_id');
        $runRasoio = (int) $this->postJson($workerRasoio.'/dispatch', [], $headersAntigo)
            ->assertAccepted()->json('data.run_id');

        ApplicationOwner::query()
            ->where('application_id', $cutinapp->id)
            ->firstOrFail()
            ->forceFill([
                'user_id' => $sucessor->id,
                'bound_by_user_id' => $sucessor->id,
                'bound_at' => now(),
            ])->save();

        $this->getJson('/api/v1/apps/cutinapp/admin/automations?app_id='.$rasoio->id, $headersAntigo)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->putJson($workerCutinapp, ['enabled' => false], $headersAntigo)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->postJson($workerCutinapp.'/dispatch', [], $headersAntigo)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->getJson('/api/v1/apps/rasoio/admin/automations?app_id='.$cutinapp->id, $headersAntigo)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.id', $runRasoio);

        $this->getJson('/api/v1/apps/cutinapp/admin/automations?app_id='.$rasoio->id, $headersNovo)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.id', $runCutinapp);
        $this->getJson('/api/v1/apps/rasoio/admin/automations', $headersNovo)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runCutinapp,
            'app_id' => $cutinapp->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio,
            'app_id' => $rasoio->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * Um HTTP 409 por execução já pendente não deve avançar o marcador usado
     * pelo agendador. Caso contrário, uma tentativa recusada adiaria a
     * próxima execução legítima sem criar job ou run.
     */
    public function test_dispatch_rejeitado_nao_avanca_marcador_de_agendamento(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $owner = $this->usuario('owner-marcador-dispatch-w03@example.test');
        $this->vincular($cutinapp->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'cutinapp',
        ];
        $worker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';

        $this->putJson($worker, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();

        $runId = (int) $this->postJson($worker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $marcador = \Illuminate\Support\Facades\DB::table('automation_worker_controls')
            ->where('app_id', $cutinapp->id)
            ->where('worker_key', 'discovery_quality')
            ->value('last_dispatched_at');
        $this->assertNotNull($marcador);

        // Avança o relógio para detectar mutação indevida do timestamp:
        // sem isso, duas requisições no mesmo segundo poderiam mascará-la.
        $this->travel(10)->minutes();
        try {
            $this->postJson($worker.'/dispatch', [], $headers)
                ->assertStatus(409);

            $this->assertSame($marcador, \Illuminate\Support\Facades\DB::table('automation_worker_controls')
                ->where('app_id', $cutinapp->id)
                ->where('worker_key', 'discovery_quality')
                ->value('last_dispatched_at'));
        } finally {
            $this->travelBack();
        }

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runId,
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);
    }

    /**
     * Reconfigurar o intervalo de um worker já enfileirado não deve alterar
     * last_dispatched_at: o agendador depende do horário do disparo real,
     * não da última edição administrativa. O controle do outro app não muda.
     */
    public function test_reconfiguracao_de_intervalo_nao_antecipa_marcador_nem_duplica_fila(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-reconfiguracao-marcador-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headers)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 60,
        ], $headers)->assertOk();

        $runCutinapp = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $runRasoio = (int) $this->postJson($workerRasoio.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $marcadorCutinapp = \Illuminate\Support\Facades\DB::table('automation_worker_controls')
            ->where('app_id', $cutinapp->id)->where('worker_key', 'discovery_quality')
            ->value('last_dispatched_at');
        $marcadorRasoio = \Illuminate\Support\Facades\DB::table('automation_worker_controls')
            ->where('app_id', $rasoio->id)->where('worker_key', 'discovery_quality')
            ->value('last_dispatched_at');
        $this->assertNotNull($marcadorCutinapp);
        $this->assertNotNull($marcadorRasoio);

        $this->travel(10)->minutes();
        try {
            // Cabeçalho Rasoio não deve redirecionar a mutação da rota Cutinapp.
            $this->putJson($workerCutinapp.'?app_id='.$rasoio->id, [
                'enabled' => true,
                'interval_minutes' => 15,
                'app_id' => $rasoio->id,
            ], $headers)
                ->assertOk()
                ->assertJsonPath('data.interval_minutes', 15);

            $this->assertSame($marcadorCutinapp, \Illuminate\Support\Facades\DB::table('automation_worker_controls')
                ->where('app_id', $cutinapp->id)->where('worker_key', 'discovery_quality')
                ->value('last_dispatched_at'));
            $this->assertSame($marcadorRasoio, \Illuminate\Support\Facades\DB::table('automation_worker_controls')
                ->where('app_id', $rasoio->id)->where('worker_key', 'discovery_quality')
                ->value('last_dispatched_at'));
        } finally {
            $this->travelBack();
        }

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 15,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 60,
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runCutinapp,
            'app_id' => $cutinapp->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio,
            'app_id' => $rasoio->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * O proprietário de um app não pode desativar um controle de worker já
     * existente em outro app. A recusa deve preservar os dois controles,
     * mesmo com app_id/application_id e X-Peter-App conflitantes.
     */
    public function test_owner_nao_desativa_controle_existente_de_outro_app_com_app_id_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-controle-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-controle-rasoio-w03@example.test');
        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headersCutinapp = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'rasoio',
        ];
        $headersRasoio = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerRasoio),
            'X-Peter-App' => 'cutinapp',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headersCutinapp)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 45,
        ], $headersRasoio)->assertOk();

        $this->putJson($workerRasoio.'?app_id='.$cutinapp->id.'&application_id='.$cutinapp->id, [
            'enabled' => false,
            'interval_minutes' => 15,
            'app_id' => $cutinapp->id,
            'application_id' => $cutinapp->id,
        ], $headersCutinapp)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->putJson($workerCutinapp.'?app_id='.$rasoio->id.'&application_id='.$rasoio->id, [
            'enabled' => false,
            'interval_minutes' => 15,
            'app_id' => $rasoio->id,
            'application_id' => $rasoio->id,
        ], $headersRasoio)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 45,
        ]);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        // O acesso legítimo continua funcional após as duas negativas.
        $this->putJson($workerCutinapp, [
            'enabled' => false,
            'interval_minutes' => 30,
        ], $headersCutinapp)
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 45,
        ]);
    }

    /**
     * O histórico operacional deve manter somente as 30 execuções mais
     * recentes DO aplicativo da rota, ordenadas por id decrescente.
     * Uma aplicação com execução mais nova não pode contaminar a janela
     * quando app_id/application_id e X-Peter-App apontam para ela.
     */
    public function test_janela_de_trinta_execucoes_preserva_ordem_e_isolamento_multiapp(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-paginacao-workers-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        $idsCutinapp = [];
        for ($indice = 0; $indice < 31; $indice++) {
            $idsCutinapp[] = (int) \Illuminate\Support\Facades\DB::table('automation_worker_runs')->insertGetId([
                'app_id' => $cutinapp->id,
                'worker_key' => 'discovery_quality',
                'status' => 'completed',
                'result' => json_encode(['sequencia' => $indice]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $idRasoio = (int) \Illuminate\Support\Facades\DB::table('automation_worker_runs')->insertGetId([
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'completed',
            'result' => json_encode(['sequencia' => 'externa']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $painel = $this->getJson(
            '/api/v1/apps/cutinapp/admin/automations?app_id='.$rasoio->id.'&application_id='.$rasoio->id,
            $headers,
        )->assertOk()->assertJsonCount(30, 'data.recent_runs');

        $idsVisiveis = array_map('intval', array_column($painel->json('data.recent_runs'), 'id'));
        $this->assertSame(array_reverse(array_slice($idsCutinapp, 1)), $idsVisiveis);
        $this->assertNotContains($idsCutinapp[0], $idsVisiveis);
        $this->assertNotContains($idRasoio, $idsVisiveis);
        $this->assertSame(30, count(array_unique($idsVisiveis)));

        $this->getJson('/api/v1/apps/rasoio/admin/automations?app_id='.$cutinapp->id, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.id', $idRasoio)
            ->assertJsonPath('data.recent_runs.0.result.sequencia', 'externa');
        $this->assertDatabaseCount('automation_worker_runs', 32);
    }

    /**
     * O lock operacional deve ser específico de (app_id, worker_key).
     * Uma execução pendente de qualidade da descoberta não pode impedir
     * um worker independente do mesmo aplicativo de entrar na fila.
     * A validação usa apenas workers genéricos, sem alterar events:discover.
     */
    public function test_fila_pendente_de_um_worker_nao_bloqueia_outro_worker_do_mesmo_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $owner = $this->usuario('owner-workers-distintos-w03@example.test');
        $this->vincular($cutinapp->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'cutinapp',
        ];
        $base = '/api/v1/apps/cutinapp/admin/automations/workers/';

        foreach (['discovery_quality', 'catalog_quality'] as $key) {
            $this->putJson($base.$key, [
                'enabled' => true,
                'interval_minutes' => 30,
            ], $headers)->assertOk();
        }

        $primeiro = (int) $this->postJson($base.'discovery_quality/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $segundo = (int) $this->postJson($base.'catalog_quality/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $this->assertNotSame($primeiro, $segundo);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $primeiro,
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $segundo,
            'app_id' => $cutinapp->id,
            'worker_key' => 'catalog_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * Uma execução pendente de um mesmo worker em outra aplicação não pode
     * serializar indevidamente o dispatch local: o lock deve usar app_id + key.
     * Dois owners distintos e JWTs distintos exercitam a rota HTTP real.
     */
    public function test_mesmo_worker_pendente_em_outro_app_nao_bloqueia_dispatch_local(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-fila-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-fila-rasoio-w03@example.test');
        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headersCutinapp = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersRasoio = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerRasoio),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => true,
            'interval_minutes' => 30,
        ], $headersCutinapp)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 45,
        ], $headersRasoio)->assertOk();

        $primeiro = (int) $this->postJson($workerCutinapp.'/dispatch', [], $headersCutinapp)
            ->assertAccepted()->json('data.run_id');
        $segundo = (int) $this->postJson($workerRasoio.'/dispatch', [], $headersRasoio)
            ->assertAccepted()->json('data.run_id');

        $this->assertNotSame($primeiro, $segundo);
        foreach ([[$primeiro, $cutinapp->id], [$segundo, $rasoio->id]] as [$runId, $appId]) {
            $this->assertDatabaseHas('automation_worker_runs', [
                'id' => $runId,
                'app_id' => $appId,
                'worker_key' => 'discovery_quality',
                'status' => 'queued',
            ]);
        }
        $this->assertDatabaseCount('automation_worker_runs', 2);
        $this->assertDatabaseCount('automation_worker_controls', 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 2);
    }

    /**
     * Strings como "false" não podem ser convertidas implicitamente para true
     * pelo PHP e reabilitar um worker. Uma validação 422 deve ser atômica:
     * controles, auditoria e filas de todos os aplicativos ficam intactos.
     * O valor booleano representado por 0 deve continuar sendo aceito.
     */
    public function test_enabled_textual_invalido_nao_reconfigura_worker_nem_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-validacao-enabled-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $workerCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $workerRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        $this->putJson($workerCutinapp, [
            'enabled' => false,
            'interval_minutes' => 30,
        ], $headers)->assertOk();
        $this->putJson($workerRasoio, [
            'enabled' => true,
            'interval_minutes' => 45,
        ], $headers)->assertOk();

        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();
        $forjado = '?app_id='.$rasoio->id.'&application_id='.$rasoio->id;

        foreach (['false', 'off', [], null] as $valorInvalido) {
            $this->putJson($workerCutinapp.$forjado, [
                'enabled' => $valorInvalido,
                'interval_minutes' => 60,
                'app_id' => $rasoio->id,
                'application_id' => $rasoio->id,
            ], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('enabled');
        }

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 45,
        ]);
        $this->assertSame($auditoriasAntes, \App\Models\ApplicationAdminAudit::query()->count());
        $this->assertDatabaseCount('automation_worker_controls', 2);
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $this->putJson($workerRasoio, [
            'enabled' => 0,
            'interval_minutes' => 45,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 45,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 30,
        ]);
    }

    /**
     * Uma execução descartada após desativação é terminal. A reativação
     * autoriza uma NOVA execução, mas nunca ressuscita o job antigo entregue
     * novamente pela fila. O worker do outro app permanece independente.
     */
    public function test_worker_pulado_pode_ser_reagendado_sem_ressuscitar_job_antigo(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $owner = $this->usuario('owner-reagendamento-pulado-w03@example.test');
        $this->vincular($cutinapp->id, $owner);
        $this->vincular($rasoio->id, $owner);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'rasoio',
        ];
        $cutinappWorker = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality';
        $rasoioWorker = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality';

        // Precondições por serviço: preserva o throttle real das rotas
        // HTTP sob teste (desativação, reativação e dispatch).
        $controle = app(\App\Domain\Automation\Services\AutomationControlService::class);
        $controle->configure($cutinapp->id, 'discovery_quality', true, 30, $owner->id);
        $controle->configure($rasoio->id, 'discovery_quality', true, 30, $owner->id);

        $antigo = (int) $this->postJson($cutinappWorker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $outroApp = (int) $this->postJson($rasoioWorker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');

        $this->putJson($cutinappWorker, [
            'enabled' => false,
            'interval_minutes' => 30,
        ], $headers)->assertOk();

        (new \App\Jobs\RunAutomationWorker($antigo))->handle();
        $terminadoEm = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $antigo)->value('finished_at');
        $this->assertNotNull($terminadoEm);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $antigo,
            'app_id' => $cutinapp->id,
            'status' => 'skipped',
        ]);

        $this->putJson($cutinappWorker, [
            'enabled' => true,
            'interval_minutes' => 30,
            'app_id' => $rasoio->id,
        ], $headers)->assertOk();

        $novo = (int) $this->postJson($cutinappWorker.'/dispatch', [], $headers)
            ->assertAccepted()->json('data.run_id');
        $this->assertNotSame($antigo, $novo);

        // Reentrega tardia não executa o job descartado após reativação.
        (new \App\Jobs\RunAutomationWorker($antigo))->handle();
        $this->assertSame($terminadoEm, \Illuminate\Support\Facades\DB::table('automation_worker_runs')
            ->where('id', $antigo)->value('finished_at'));
        $this->postJson($cutinappWorker.'/dispatch', [], $headers)->assertStatus(409);

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $antigo, 'app_id' => $cutinapp->id, 'status' => 'skipped',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $novo, 'app_id' => $cutinapp->id, 'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $outroApp, 'app_id' => $rasoio->id, 'status' => 'queued',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 3);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 3);
    }


    /**
     * O kill switch global deve impedir a execução de jobs já enfileirados
     * em todas as aplicações. Reativá-lo não pode ressuscitar jobs descartados,
     * mas deve permitir uma nova execução explícita no app autorizado.
     */
    public function test_kill_switch_global_descarta_filas_sem_ressuscitar_jobs_apos_reativacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-killswitch-cutinapp-w03@example.test');
        $ownerRasoio = $this->usuario('owner-killswitch-rasoio-w03@example.test');
        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        // Configuração por serviço evita gastar o throttle das rotas de dispatch.
        $controle = app(\App\Domain\Automation\Services\AutomationControlService::class);
        $controle->configure($cutinapp->id, 'discovery_quality', true, 30, $ownerCutinapp->id);
        $controle->configure($rasoio->id, 'discovery_quality', true, 30, $ownerRasoio->id);

        $headersCutinapp = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersRasoio = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerRasoio),
            'X-Peter-App' => 'rasoio',
        ];
        $rotaCutinapp = '/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality/dispatch';
        $rotaRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality/dispatch';

        $runCutinapp = (int) $this->postJson($rotaCutinapp, [], $headersCutinapp)
            ->assertAccepted()->json('data.run_id');
        $runRasoio = (int) $this->postJson($rotaRasoio, [], $headersRasoio)
            ->assertAccepted()->json('data.run_id');

        config()->set('automation_workers.enabled', false);
        foreach ([$runCutinapp, $runRasoio] as $runId) {
            (new \App\Jobs\RunAutomationWorker($runId))->handle();
        }

        $conclusoes = [];
        foreach ([[$runCutinapp, $cutinapp->id], [$runRasoio, $rasoio->id]] as [$runId, $appId]) {
            $this->assertDatabaseHas('automation_worker_runs', [
                'id' => $runId,
                'app_id' => $appId,
                'status' => 'skipped',
            ]);
            $conclusoes[$runId] = \Illuminate\Support\Facades\DB::table('automation_worker_runs')
                ->where('id', $runId)->value('finished_at');
            $this->assertNotNull($conclusoes[$runId]);
        }

        config()->set('automation_workers.enabled', true);
        foreach ([$runCutinapp, $runRasoio] as $runId) {
            (new \App\Jobs\RunAutomationWorker($runId))->handle();
            $this->assertSame($conclusoes[$runId], \Illuminate\Support\Facades\DB::table('automation_worker_runs')
                ->where('id', $runId)->value('finished_at'));
        }

        $novoRun = (int) $this->postJson($rotaCutinapp, [], $headersCutinapp)
            ->assertAccepted()->json('data.run_id');
        $this->assertNotSame($runCutinapp, $novoRun);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $novoRun,
            'app_id' => $cutinapp->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio,
            'app_id' => $rasoio->id,
            'status' => 'skipped',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 3);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 3);
    }

    /**
     * A rotação da versão de autenticação deve invalidar imediatamente
     * o JWT antigo antes de qualquer leitura ou dispatch de worker.
     * A revogação é individual: não derruba a fila de outro aplicativo
     * administrado por um usuário com sessão válida.
     */
    public function test_rotacao_de_versao_jwt_revoga_operacao_sem_afetar_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-jwt-revogado-w03@example.test');
        $ownerRasoio = $this->usuario('owner-jwt-valido-w03@example.test');
        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        // Configurar via serviço evita consumir o throttle HTTP de dispatch.
        $controle = app(\App\Domain\Automation\Services\AutomationControlService::class);
        $controle->configure($cutinapp->id, 'discovery_quality', true, 30, $ownerCutinapp->id);
        $controle->configure($rasoio->id, 'discovery_quality', true, 30, $ownerRasoio->id);

        $headersAntigos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersValidos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerRasoio),
            'X-Peter-App' => 'rasoio',
        ];
        $painelCutinapp = '/api/v1/apps/cutinapp/admin/automations';
        $dispatchCutinapp = $painelCutinapp.'/workers/discovery_quality/dispatch';
        $dispatchRasoio = '/api/v1/apps/rasoio/admin/automations/workers/discovery_quality/dispatch';

        $this->getJson($painelCutinapp, $headersAntigos)->assertOk();

        // Simula revogação centralizada de todas as sessões anteriores.
        $versaoAnterior = max((int) $ownerCutinapp->auth_version, 1);
        User::query()->whereKey($ownerCutinapp->id)
            ->update(['auth_version' => $versaoAnterior + 1]);

        $this->getJson($painelCutinapp, $headersAntigos)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');
        $this->postJson($dispatchCutinapp, [], $headersAntigos)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');

        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        $runRasoio = (int) $this->postJson($dispatchRasoio, [], $headersValidos)
            ->assertAccepted()->json('data.run_id');

        $this->assertDatabaseHas('automation_worker_runs', [
            'id' => $runRasoio,
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('automation_worker_runs', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
        ]);
        $this->assertDatabaseCount('automation_worker_runs', 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAutomationWorker::class, 1);
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

    /**
     * Revogar um JWT precisa bloquear também PUT de configuração: negar apenas
     * leitura e dispatch deixaria a conta revogada alterar jobs futuros.
     * A revogação de um usuário não altera o controle de outra aplicação.
     */
    public function test_jwt_revogado_nao_reconfigura_controle_de_worker_nem_afeta_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $rasoio = $this->applicationFixture('rasoio');
        $ownerCutinapp = $this->usuario('owner-jwt-put-revogado-w03@example.test');
        $ownerRasoio = $this->usuario('owner-jwt-put-valido-w03@example.test');
        $this->vincular($cutinapp->id, $ownerCutinapp);
        $this->vincular($rasoio->id, $ownerRasoio);

        config()->set('automation_workers.enabled', true);
        \Illuminate\Support\Facades\Queue::fake();

        $controle = app(\App\Domain\Automation\Services\AutomationControlService::class);
        $controle->configure($cutinapp->id, 'discovery_quality', true, 30, $ownerCutinapp->id);
        $controle->configure($rasoio->id, 'discovery_quality', true, 45, $ownerRasoio->id);

        $headersRevogados = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerCutinapp),
            'X-Peter-App' => 'cutinapp',
        ];
        $headersValidos = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($ownerRasoio),
            'X-Peter-App' => 'rasoio',
        ];
        $auditoriasAntes = \App\Models\ApplicationAdminAudit::query()->count();

        // A versão muda no banco sem trocar o bearer apresentado ao endpoint.
        $versaoAnterior = max((int) $ownerCutinapp->auth_version, 1);
        User::query()->whereKey($ownerCutinapp->id)
            ->update(['auth_version' => $versaoAnterior + 1]);

        $this->putJson('/api/v1/apps/cutinapp/admin/automations/workers/discovery_quality', [
            'enabled' => false,
            'interval_minutes' => 60,
            'app_id' => $rasoio->id,
            'application_id' => $rasoio->id,
        ], $headersRevogados)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');

        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 30,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 45,
        ]);
        $this->assertSame($auditoriasAntes, \App\Models\ApplicationAdminAudit::query()->count());
        $this->assertDatabaseCount('automation_worker_runs', 0);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunAutomationWorker::class);

        // Um proprietário independente ainda pode administrar seu próprio app.
        $this->putJson('/api/v1/apps/rasoio/admin/automations/workers/discovery_quality', [
            'enabled' => false,
            'interval_minutes' => 50,
        ], $headersValidos)
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $rasoio->id,
            'worker_key' => 'discovery_quality',
            'enabled' => false,
            'interval_minutes' => 50,
        ]);
        $this->assertDatabaseHas('automation_worker_controls', [
            'app_id' => $cutinapp->id,
            'worker_key' => 'discovery_quality',
            'enabled' => true,
            'interval_minutes' => 30,
        ]);
    }

}
