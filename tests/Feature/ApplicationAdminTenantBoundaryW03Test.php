<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Middleware\EnsureApplicationAdmin;
use App\Models\ApplicationOwner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Regressões do limite entre o administrador global e a delegação por aplicação.
 * Nenhum endpoint administrativo novo é pressuposto ou ativado por estes testes.
 */
final class ApplicationAdminTenantBoundaryW03Test extends TestCase
{
    use RefreshDatabase;

    public function test_rotas_administrativas_exigem_registro_e_middleware_de_seguranca(): void
    {
        // Este teste não registra nem habilita endpoints. Ele falha explicitamente
        // quando o arquivo de rotas existe, mas não é carregado pelo Laravel.
        $rotas = [
            ['GET', 'api/v1/apps/{application}/admin/context', null],
            ['GET', 'api/v1/apps/{application}/admin/profiles', 'admin.access.manage'],
            ['POST', 'api/v1/apps/{application}/admin/profiles', 'admin.access.manage'],
            ['POST', 'api/v1/apps/{application}/admin/assignments', 'admin.access.manage'],
        ];

        foreach ($rotas as [$metodo, $uri, $permissao]) {
            $registrada = collect(Route::getRoutes()->getRoutes())->first(
                static fn ($rota) => $rota->uri() === $uri
                    && in_array($metodo, $rota->methods(), true),
            );

            $this->assertNotNull(
                $registrada,
                "Rota {$metodo} {$uri} não registrada; verificar route:list e aprovação de segurança.",
            );

            $middleware = $registrada->gatherMiddleware();
            foreach (['app.context', 'auth:api', 'token.version', EnsureApplicationAdmin::class] as $obrigatorio) {
                $this->assertContains(
                    $obrigatorio,
                    $middleware,
                    "Rota {$metodo} {$uri} sem middleware obrigatório: {$obrigatorio}.",
                );
            }

            if ($permissao !== null) {
                $this->assertContains(
                    EnsureApplicationAdmin::class.':'.$permissao,
                    $middleware,
                    "Rota {$metodo} {$uri} sem autorização de gestão de acessos.",
                );
            }
        }
    }

    public function test_rota_global_exige_autenticacao_e_rejeita_usuario_comum_mesmo_com_app_id(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');

        $this->getJson('/api/admin/applications')->assertUnauthorized();
        $this->getJson('/api/v1/apps/cutinapp/admin/context')->assertUnauthorized();

        $usuario = $this->criarUsuario('usuario-cutinapp-w03@example.test');

        $cabecalhos = $this->cabecalhos($usuario, 'cutinapp');
        $this->getJson('/api/admin/applications?app_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden();

        // Autenticação válida não equivale a vínculo administrativo.
        // O app_id informado pelo cliente não deve autorizar a aplicação.
        $this->getJson('/api/v1/apps/cutinapp/admin/context?app_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
    }

    public function test_delegado_de_outra_aplicacao_nao_herda_privilegios_na_cutinapp_ou_no_admin_global(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-externo-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($externo->id, $root, [
            'name' => 'Operador externo',
            'permissions' => ['dashboard.view'],
        ]);
        $administracao->assign($externo->id, $root, $delegado->email, $perfil->id);

        $this->assertTrue($administracao->hasAccess($delegado, $externo->id));
        $this->assertTrue($administracao->hasPermission($delegado, $externo->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasAccess($delegado, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'dashboard.view'));
        $this->assertFalse($administracao->hasPermission(
            $delegado,
            $externo->id,
            ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->isRoot($delegado));

        $contexto = $administracao->context($delegado, $cutinapp->id);
        $this->assertFalse($contexto['authorized']);
        $this->assertSame([], $contexto['permissions']);

        // O parâmetro e o cabeçalho do cliente não concedem acesso global.
        $this->withHeaders($this->cabecalhos($delegado, 'rasoio'))
            ->getJson('/api/admin/applications?app_id='.$externo->id)
            ->assertForbidden();
    }

    public function test_delegado_cutinapp_pode_usar_permissao_local_mas_nao_concede_grants_nem_gerencia_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-cutinapp-w03@example.test');
        $terceiro = $this->criarUsuario('terceiro-cutinapp-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura de eventos Cutinapp',
            'permissions' => ['dashboard.view', 'events.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);

        $contexto = $administracao->context($delegado->fresh(), $cutinapp->id);
        $this->assertTrue($contexto['authorized']);
        $this->assertSame('delegated_admin', $contexto['authority_level']);
        $this->assertFalse($contexto['is_owner']);
        $this->assertSame(['dashboard.view', 'events.view'], $contexto['permissions']);
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.view'));
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->hasAccess($delegado, $externo->id));
        $this->assertFalse($administracao->context($delegado, $externo->id)['authorized']);
        $this->assertFalse($administracao->isRoot($delegado));

        try {
            $administracao->createProfile($cutinapp->id, $delegado, [
                'name' => 'Escalação indevida',
                'permissions' => ['users.manage'],
            ]);
            $this->fail('Delegado não pode criar perfis administrativos.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('application_admin_profiles', [
                'application_id' => $cutinapp->id,
                'slug' => 'escalacao-indevida',
            ]);
        }

        try {
            $administracao->assign($cutinapp->id, $delegado, $terceiro->email, $perfil->id);
            $this->fail('Delegado não pode conceder acesso a terceiros.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('application_admin_assignments', [
                'application_id' => $cutinapp->id,
                'user_id' => $terceiro->id,
            ]);
        }
    }

    public function test_revogacao_remove_acesso_e_permissoes_da_aplicacao_sem_tornar_delegado_root(): void
    {
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-revogado-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($externo->id, $root, [
            'name' => 'Operador revogável',
            'permissions' => ['dashboard.view'],
        ]);
        $vinculo = $administracao->assign($externo->id, $root, $delegado->email, $perfil->id);

        $this->assertTrue($administracao->hasAccess($delegado, $externo->id));
        $this->assertTrue($administracao->hasPermission($delegado, $externo->id, 'dashboard.view'));

        $administracao->revoke($externo->id, $vinculo->id, $root);

        // Revogação imediata no mesmo objeto User e na mesma instância do serviço:
        // não pode depender de novo login, refresh do modelo ou nova requisição.
        $this->assertFalse($administracao->hasAccess($delegado, $externo->id));
        $this->assertFalse($administracao->hasPermission($delegado, $externo->id, 'dashboard.view'));
        $this->assertFalse($administracao->context($delegado, $externo->id)['authorized']);
        $this->assertDatabaseHas('application_admin_assignments', [
            'id' => $vinculo->id,
            'application_id' => $externo->id,
            'user_id' => $delegado->id,
            'status' => 'revoked',
        ]);

        $revogado = $delegado->fresh();
        $this->assertFalse($administracao->hasAccess($revogado, $externo->id));
        $this->assertFalse($administracao->hasPermission($revogado, $externo->id, 'dashboard.view'));
        $this->assertFalse($administracao->context($revogado, $externo->id)['authorized']);
        $this->assertFalse($administracao->isRoot($revogado));

        $this->withHeaders($this->cabecalhos($revogado, 'rasoio'))
            ->getJson('/api/admin/applications?app_id='.$externo->id)
            ->assertForbidden();
    }

    public function test_reconcessao_apos_revogacao_nao_ressuscita_permissoes_antigas_nem_acesso_cruzado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-reconcessao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilAntigo = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Gestão temporária de eventos',
            'permissions' => ['events.view', 'events.manage'],
        ]);
        $perfilRestrito = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitura após revogação',
            'permissions' => ['events.view'],
        ]);

        $vinculo = $administracao->assign($cutinapp->id, $root, $delegado->email, $perfilAntigo->id);
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));

        $administracao->revoke($cutinapp->id, $vinculo->id, $root);
        $this->assertFalse($administracao->hasAccess($delegado, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));

        // Reutilizar o mesmo User e serviço detecta permissões obsoletas em cache.
        $novoVinculo = $administracao->assign($cutinapp->id, $root, $delegado->email, $perfilRestrito->id);
        $this->assertSame((int) $vinculo->id, (int) $novoVinculo->id);
        $this->assertSame((int) $perfilRestrito->id, (int) $novoVinculo->profile_id);
        $this->assertTrue($administracao->hasAccess($delegado, $cutinapp->id));
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.view'));
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertSame(['events.view'], $administracao->context($delegado, $cutinapp->id)['permissions']);
        $this->assertFalse($administracao->hasAccess($delegado, $externo->id));
        $this->assertFalse($administracao->isRoot($delegado));
    }

    public function test_revogacao_de_vinculo_de_outra_aplicacao_e_negada_sem_afetar_acesso_original(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-escopo-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($externo->id, $root, [
            'name' => 'Operador isolado',
            'permissions' => ['dashboard.view'],
        ]);
        $vinculo = $administracao->assign($externo->id, $root, $delegado->email, $perfil->id);

        try {
            // Mesmo o Owner não pode revogar usando o app_id de outra aplicação.
            $administracao->revoke($cutinapp->id, $vinculo->id, $root);
            $this->fail('Revogação cruzada não deveria localizar o vínculo.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($administracao->hasAccess($delegado->fresh(), $externo->id));
            $this->assertTrue($administracao->hasPermission($delegado->fresh(), $externo->id, 'dashboard.view'));
            $this->assertFalse($administracao->hasAccess($delegado->fresh(), $cutinapp->id));
        }
    }

    public function test_perfil_de_outra_aplicacao_nao_pode_ser_atribuido_por_troca_de_app_id(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-perfil-cruzado-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfilExterno = $administracao->createProfile($externo->id, $root, [
            'name' => 'Perfil exclusivo do app externo',
            'permissions' => ['dashboard.view'],
        ]);

        try {
            $administracao->assign($cutinapp->id, $root, $delegado->email, $perfilExterno->id);
            $this->fail('Um perfil externo não pode ser atribuído ao contexto Cutinapp.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('application_admin_assignments', [
                'application_id' => $cutinapp->id,
                'user_id' => $delegado->id,
            ]);
            $this->assertFalse($administracao->hasAccess($delegado, $cutinapp->id));
            $this->assertFalse($administracao->hasAccess($delegado, $externo->id));
        }
    }

    public function test_contexto_http_nao_aceita_troca_de_app_e_revoga_jwt_existente_sem_novo_login(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-http-revogacao-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Consulta HTTP Cutinapp',
            'permissions' => ['dashboard.view'],
        ]);
        $vinculo = $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);
        $token = JWTAuth::fromUser($delegado);
        $cabecalhos = [
            'Authorization' => 'Bearer '.$token,
            // O slug no cabeçalho é controlado pelo cliente e não substitui a rota.
            'X-Peter-App' => $externo->slug,
        ];

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertHeader('X-Peter-Application', 'cutinapp')
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.authority_level', 'delegated_admin');

        // Nem o cabeçalho Cutinapp nem app_id na query autorizam outro app.
        $this->getJson('/api/v1/apps/rasoio/admin/context?app_id='.$cutinapp->id, [
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])->assertForbidden()->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $administracao->revoke($cutinapp->id, $vinculo->id, $root);

        // Reutilizar exatamente o mesmo JWT detecta permissões em cache ou sessão.
        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->assertFalse($administracao->hasAccess($delegado, $cutinapp->id));
    }

    public function test_delegado_nao_pode_criar_perfis_ou_conceder_acessos_pelo_http(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-grants-http-w03@example.test');
        $destinatario = $this->criarUsuario('destinatario-grants-http-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Leitor de painel HTTP',
            'permissions' => ['dashboard.view'],
        ]);
        $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);
        $cabecalhos = $this->cabecalhos($delegado, 'cutinapp');

        // O delegado possui acesso ao painel, mas nunca pode conceder privilégios.
        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.authorized', true);

        $this->postJson('/api/v1/apps/cutinapp/admin/profiles', [
            'name' => 'Perfil elevado via HTTP',
            'permissions' => ['users.manage'],
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->postJson('/api/v1/apps/cutinapp/admin/assignments', [
            'email' => $destinatario->email,
            'profile_id' => $perfil->id,
        ], $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->getJson('/api/v1/apps/cutinapp/admin/profiles', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');

        $this->assertDatabaseMissing('application_admin_profiles', [
            'application_id' => $cutinapp->id,
            'slug' => 'perfil-elevado-via-http',
        ]);
        $this->assertDatabaseMissing('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $destinatario->id,
        ]);
        $this->assertFalse($administracao->hasAccess($destinatario, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
    }

    public function test_delegado_com_catalogo_completo_nao_recebe_poder_de_grant_ou_acesso_global(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-catalogo-completo-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        // Mesmo a delegação de TODAS as permissões públicas não inclui
        // admin.access.manage nem autoridade root/global.
        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Todas as permissões delegáveis',
            'permissions' => ApplicationAdminService::PERMISSION_CATALOG,
        ]);
        $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);
        $cabecalhos = $this->cabecalhos($delegado, 'cutinapp');

        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'users.manage'));
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->isRoot($delegado));
        $this->assertFalse($administracao->hasAccess($delegado, $externo->id));

        $this->getJson('/api/v1/apps/cutinapp/admin/context', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.authority_level', 'delegated_admin');
        $this->getJson('/api/v1/apps/cutinapp/admin/profiles', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->getJson('/api/v1/apps/rasoio/admin/context?app_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
        $this->getJson('/api/admin/applications?app_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden();
    }

    public function test_reducao_de_permissoes_do_perfil_revoga_poderes_imediatamente_sem_refresh_do_usuario(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('delegado-permissoes-reduzidas-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Gestão temporária de eventos',
            'permissions' => ['events.view', 'events.manage'],
        ]);
        $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);

        // O mesmo objeto User e serviço devem observar a redução sem refresh do modelo.
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.view'));
        $this->assertFalse($administracao->isRoot($delegado));

        $administracao->updateProfile($cutinapp->id, $perfil->id, $root, [
            'permissions' => ['events.view'],
        ]);

        $this->assertTrue($administracao->hasAccess($delegado, $cutinapp->id));
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));
        $this->assertTrue($administracao->hasPermission($delegado, $cutinapp->id, 'events.view'));
        $this->assertSame(['events.view'], $administracao->context($delegado, $cutinapp->id)['permissions']);
        $this->assertFalse($administracao->hasPermission(
            $delegado, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        // Uma tentativa de introduzir permissão reservada não pode desfazer a redução.
        try {
            $administracao->updateProfile($cutinapp->id, $perfil->id, $root, [
                'permissions' => ['events.view', ApplicationAdminService::ACCESS_MANAGE_PERMISSION],
            ]);
            $this->fail('Permissão reservada não pode ser inserida no perfil delegado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('permissions', $erro->errors());
        }

        $this->assertSame(['events.view'], $administracao->context($delegado, $cutinapp->id)['permissions']);
        $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'events.manage'));
    }

    public function test_administrador_principal_da_cutinapp_nao_e_root_do_ecossistema(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $administradorLocal = $this->criarUsuario('cutinapp@gmail.com');

        // Vinculação de fixture, sem bootstrap nem concessão em runtime.
        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $administradorLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $administradorLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $this->assertTrue($administracao->isOwner($administradorLocal, $cutinapp->id));
        $this->assertFalse($administracao->isRoot($administradorLocal));
        $this->assertTrue($administracao->hasPermission(
            $administradorLocal, $cutinapp->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->isOwner($administradorLocal, $externo->id));
        $this->assertFalse($administracao->hasAccess($administradorLocal, $externo->id));
        $this->assertFalse($administracao->hasPermission(
            $administradorLocal, $externo->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($administracao->context($administradorLocal, $externo->id)['authorized']);

        $contexto = $administracao->context($administradorLocal, $cutinapp->id);
        $this->assertTrue($contexto['authorized']);
        $this->assertTrue($contexto['is_owner']);
        $this->assertSame('owner', $contexto['authority_level']);
        // Regressão P0: o payload não pode promover um owner local a root global.
        $this->assertFalse($contexto['is_root'],
            'Owner da Cutinapp não é root global; revisar ApplicationAdminService::context antes de ativar a UI.');
    }

    public function test_owner_local_nao_pode_criar_perfil_nem_conceder_grant_em_outro_app(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $ownerLocal = $this->criarUsuario('cutinapp@gmail.com');
        $destinatario = $this->criarUsuario('destinatario-owner-externo-w03@example.test');

        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $ownerLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $ownerLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($ownerLocal, $externo->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));

        try {
            $administracao->createProfile($externo->id, $ownerLocal, [
                'name' => 'Perfil externo indevido',
                'permissions' => ['users.manage'],
            ]);
            $this->fail('Owner local não pode criar perfil administrativo em outra aplicação.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('application_admin_profiles', [
                'application_id' => $externo->id,
                'slug' => 'perfil-externo-indevido',
            ]);
        }

        // Um perfil válido do outro app também não pode ser usado para contornar
        // o isolamento. O método assign() deve negar antes de alterar vínculos.
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $perfilExterno = $administracao->createProfile($externo->id, $root, [
            'name' => 'Perfil legítimo externo',
            'permissions' => ['dashboard.view'],
        ]);

        try {
            $administracao->assign($externo->id, $ownerLocal, $destinatario->email, $perfilExterno->id);
            $this->fail('Owner local não pode conceder acesso administrativo a outra aplicação.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('application_admin_assignments', [
                'application_id' => $externo->id,
                'user_id' => $destinatario->id,
            ]);
        }

        $this->assertFalse($administracao->hasAccess($destinatario, $externo->id));
    }

    public function test_owner_local_nao_pode_delegar_perfil_ao_root_global_mesmo_com_email_em_maiusculas(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $ownerLocal = $this->criarUsuario('cutinapp@gmail.com');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);

        // A identidade Owner local é legítima apenas para a aplicação vinculada.
        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $ownerLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $ownerLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $perfil = $administracao->createProfile($cutinapp->id, $ownerLocal, [
            'name' => 'Perfil operacional restrito',
            'permissions' => ['dashboard.view'],
        ]);

        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));
        $this->assertTrue($administracao->isRoot($root));

        // O alvo não pode virar delegado nem quando o email é enviado com outra caixa.
        try {
            $administracao->assign($cutinapp->id, $ownerLocal, strtoupper($root->email), $perfil->id);
            $this->fail('Owner local não pode converter o root do ecossistema em delegado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('email', $erro->errors());
        }

        $this->assertDatabaseMissing('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $root->id,
        ]);
        $this->assertTrue($administracao->isRoot($root));
        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
    }

    public function test_auditoria_delegada_nao_expoe_outro_app_e_revogacao_bloqueia_consulta(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $delegado = $this->criarUsuario('auditor-cutinapp-w03@example.test');
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Auditoria restrita Cutinapp',
            'permissions' => ['audit.view'],
        ]);
        $vinculo = $administracao->assign($cutinapp->id, $root, $delegado->email, $perfil->id);

        // Marcadores independentes demonstram que a consulta não vaza registros
        // da outra aplicação, mesmo quando ambos existem no banco compartilhado.
        $administracao->auditAction($cutinapp->id, $root, null, 'auditoria_local_w03');
        $administracao->auditAction($externo->id, $root, null, 'auditoria_externa_w03');

        $registros = $administracao->audit($cutinapp->id, $delegado, 100)->items();
        $this->assertNotEmpty($registros);
        $this->assertContains('auditoria_local_w03', array_map(
            static fn ($registro) => $registro->action, $registros,
        ));
        $this->assertNotContains('auditoria_externa_w03', array_map(
            static fn ($registro) => $registro->action, $registros,
        ));
        foreach ($registros as $registro) {
            $this->assertSame($cutinapp->id, (int) $registro->application_id);
        }

        try {
            $administracao->audit($externo->id, $delegado);
            $this->fail('Delegado da Cutinapp não pode consultar auditoria de outro aplicativo.');
        } catch (AuthorizationException) {
            $this->assertFalse($administracao->hasPermission($delegado, $externo->id, 'audit.view'));
        }

        $administracao->revoke($cutinapp->id, $vinculo->id, $root);
        try {
            // Mesmo User e mesmo serviço: a revogação deve valer imediatamente.
            $administracao->audit($cutinapp->id, $delegado);
            $this->fail('Auditoria deve ser negada após revogação sem novo login.');
        } catch (AuthorizationException) {
            $this->assertFalse($administracao->hasPermission($delegado, $cutinapp->id, 'audit.view'));
        }
    }

    public function test_owner_local_nao_pode_consultar_auditoria_de_outra_aplicacao(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $ownerLocal = $this->criarUsuario('cutinapp@gmail.com');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);

        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $ownerLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $ownerLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $administracao->auditAction($cutinapp->id, $root, null, 'auditoria_owner_local_w03');
        $administracao->auditAction($externo->id, $root, null, 'auditoria_owner_externo_w03');

        // A autoridade owner é local: nem a auditoria nem seus metadados
        // podem atravessar o limite de aplicação por troca de app_id.
        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));
        $this->assertFalse($administracao->hasPermission($ownerLocal, $externo->id, 'audit.view'));

        $acoesLocais = array_map(
            static fn ($registro) => $registro->action,
            $administracao->audit($cutinapp->id, $ownerLocal, 100)->items(),
        );
        $this->assertContains('auditoria_owner_local_w03', $acoesLocais);
        $this->assertNotContains('auditoria_owner_externo_w03', $acoesLocais);

        try {
            $administracao->audit($externo->id, $ownerLocal);
            $this->fail('Owner local não pode consultar auditoria de outra aplicação.');
        } catch (AuthorizationException) {
            $this->assertFalse($administracao->hasAccess($ownerLocal, $externo->id));
        }
    }

    public function test_owner_local_nao_acessa_administracao_global_com_app_id_forjado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $ownerLocal = $this->criarUsuario('cutinapp@gmail.com');

        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $ownerLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $ownerLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));

        // Um owner da aplicação não vira administrador global por query/header.
        $cabecalhos = $this->cabecalhos($ownerLocal, 'cutinapp');
        $this->getJson('/api/admin/applications?app_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden();
        $this->getJson('/api/admin/applications?application_id='.$cutinapp->id, $cabecalhos)
            ->assertForbidden();
    }

    public function test_identidade_root_nao_pode_ser_substituida_por_perfil_delegado(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $administracao = app(ApplicationAdminService::class);

        $perfil = $administracao->createProfile($cutinapp->id, $root, [
            'name' => 'Perfil delegado restrito',
            'permissions' => ['dashboard.view'],
        ]);

        try {
            $administracao->assign($cutinapp->id, $root, $root->email, $perfil->id);
            $this->fail('A identidade root não pode receber um perfil delegado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('email', $erro->errors());
        }

        $this->assertTrue($administracao->isOwner($root, $cutinapp->id));
        $this->assertTrue($administracao->isRoot($root));
        $this->assertTrue($administracao->context($root, $cutinapp->id)['is_root']);
        $this->assertTrue($administracao->hasPermission(
            $root,
            $cutinapp->id,
            ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertDatabaseMissing('application_admin_assignments', [
            'application_id' => $cutinapp->id,
            'user_id' => $root->id,
        ]);
    }

    public function test_owner_local_com_delegacao_em_outro_app_nao_herda_poder_de_gerenciar_eventos(): void
    {
        $cutinapp = $this->applicationFixture('cutinapp');
        $externo = $this->applicationFixture('rasoio');
        $root = $this->criarUsuario(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $ownerLocal = $this->criarUsuario('owner-local-delegado-w03@example.test');

        ApplicationOwner::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $ownerLocal->id,
            'bootstrap_email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'bound_at' => now(),
            'bound_by_user_id' => $ownerLocal->id,
        ]);

        $administracao = app(ApplicationAdminService::class);
        $perfil = $administracao->createProfile($externo->id, $root, [
            'name' => 'Consulta de eventos externa',
            'permissions' => ['events.view'],
        ]);
        $administracao->assign($externo->id, $root, $ownerLocal->email, $perfil->id);

        // A autoridade de owner não atravessa aplicações quando o mesmo usuário
        // recebe uma delegação limitada em outro produto do ecossistema.
        $this->assertTrue($administracao->isOwner($ownerLocal, $cutinapp->id));
        $this->assertFalse($administracao->isOwner($ownerLocal, $externo->id));
        $this->assertFalse($administracao->isRoot($ownerLocal));
        $this->assertTrue($administracao->hasPermission($ownerLocal, $cutinapp->id, 'events.manage'));
        $this->assertTrue($administracao->hasPermission($ownerLocal, $externo->id, 'events.view'));
        $this->assertFalse($administracao->hasPermission($ownerLocal, $externo->id, 'events.manage'));
        $this->assertFalse($administracao->hasPermission(
            $ownerLocal, $externo->id, ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $contexto = $administracao->context($ownerLocal, $externo->id);
        $this->assertTrue($contexto['authorized']);
        $this->assertFalse($contexto['is_owner']);
        $this->assertFalse($contexto['is_root']);
        $this->assertSame('delegated_admin', $contexto['authority_level']);
        $this->assertSame(['events.view'], $contexto['permissions']);

        $cabecalhos = $this->cabecalhos($ownerLocal, 'cutinapp');
        $this->getJson('/api/v1/apps/rasoio/admin/context?app_id='.$cutinapp->id, $cabecalhos)
            ->assertOk()
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.is_root', false)
            ->assertJsonPath('data.authority_level', 'delegated_admin');
        $this->getJson('/api/v1/apps/rasoio/admin/profiles', $cabecalhos)
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_FORBIDDEN');
    }

    private function cabecalhos(User $usuario, string $app): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($usuario),
            'X-Peter-App' => $app,
        ];
    }

    private function criarUsuario(string $email): User
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