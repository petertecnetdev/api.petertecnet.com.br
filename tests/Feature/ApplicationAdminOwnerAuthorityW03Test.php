<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Domain\Platform\Services\ApplicationOwnerAuthorityService;
use App\Models\ApplicationOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
