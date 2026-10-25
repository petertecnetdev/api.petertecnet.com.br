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
