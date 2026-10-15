<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Application;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutinappOperationalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_app_administrator_can_operate_cutinapp_without_ecosystem_role(): void
    {
        $cutinapp = Application::query()->firstOrCreate(['slug' => 'cutinapp'], ['name' => 'Cutinapp', 'is_active' => true]);
        $other = Application::query()->create(['name' => 'Outro', 'slug' => 'outro', 'is_active' => true]);
        $root = $this->user('petertecnet@gmail.com');
        $curator = $this->user('cutinapp@gmail.com');
        $outsider = $this->user('outro@example.com');
        $admin = app(ApplicationAdminService::class);

        $profile = $admin->createProfile($cutinapp->id, $root, [
            'name' => 'Administração Cutinapp',
            'permissions' => ['establishments.manage', 'events.manage', 'tickets.manage', 'artists.manage'],
        ]);
        $admin->assign($cutinapp->id, $root, $curator->email, $profile->id);
        app(ApplicationContext::class)->set($cutinapp);

        foreach (['production_create', 'production_edit', 'event_create', 'event_edit', 'ticket_create', 'artist_curate'] as $action) {
            $this->assertTrue($curator->hasPermission($action), $action);
            $this->assertFalse($outsider->hasPermission($action), 'isolamento: '.$action);
            $this->assertFalse($curator->hasPermission($action, $other->id), 'isolamento explícito: '.$action);
        }
        $this->assertFalse($curator->hasProfile('Administrador'));
        $this->assertFalse($admin->hasPermission($curator, $cutinapp->id, 'admin.access.manage'));

        app(ApplicationContext::class)->set($other);
        $this->assertFalse($curator->hasPermission('production_create'));
        $this->assertFalse($curator->hasPermission('artist_curate'));
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'first_name' => 'Teste',
            'last_name' => 'Curadoria',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }
}
