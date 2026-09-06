<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Application;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApplicationAdminService $service;
    private Application $application;
    private User $root;
    private User $delegated;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ApplicationAdminService::class);
        $this->application = Application::query()->create([
            'name' => 'Test Application',
            'slug' => 'test-application',
            'is_active' => true,
        ]);
        $this->root = User::factory()->create(['email' => ApplicationAdminService::ROOT_ADMIN_EMAIL]);
        $this->delegated = User::factory()->create(['email' => 'delegated-admin@example.com']);
        $this->outsider = User::factory()->create(['email' => 'outsider@example.com']);
    }

    public function test_root_admin_has_access_and_reserved_permission_without_assignment(): void
    {
        $context = $this->service->context($this->root, $this->application->id);

        $this->assertTrue($context['authorized']);
        $this->assertTrue($context['is_root']);
        $this->assertTrue($this->service->hasPermission(
            $this->root,
            $this->application->id,
            ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
    }

    public function test_delegated_admin_receives_only_profile_permissions_and_can_be_revoked(): void
    {
        $profile = $this->service->createProfile($this->application->id, $this->root, [
            'name' => 'Operação de eventos',
            'permissions' => ['dashboard.view', 'events.view', 'events.manage', 'tickets.view'],
        ]);

        $assignment = $this->service->assign(
            $this->application->id,
            $this->root,
            $this->delegated->email,
            $profile->id,
        );

        $this->assertTrue($this->service->hasAccess($this->delegated, $this->application->id));
        $this->assertTrue($this->service->hasPermission($this->delegated, $this->application->id, 'events.manage'));
        $this->assertFalse($this->service->hasPermission($this->delegated, $this->application->id, 'finance.refund'));
        $this->assertFalse($this->service->hasPermission(
            $this->delegated,
            $this->application->id,
            ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));
        $this->assertFalse($this->service->hasAccess($this->outsider, $this->application->id));

        $this->service->revoke($this->application->id, $assignment->id, $this->root);

        $this->assertFalse($this->service->hasAccess($this->delegated->fresh(), $this->application->id));
    }

    public function test_delegated_admin_cannot_grant_privileges_even_when_profile_data_is_tampered(): void
    {
        $profile = $this->service->createProfile($this->application->id, $this->root, [
            'name' => 'Administrador delegado',
            'permissions' => ['dashboard.view', 'users.manage'],
        ]);

        $this->service->assign(
            $this->application->id,
            $this->root,
            $this->delegated->email,
            $profile->id,
        );

        $profile->forceFill([
            'permissions' => ['dashboard.view', ApplicationAdminService::ACCESS_MANAGE_PERMISSION],
        ])->save();

        $this->assertFalse($this->service->hasPermission(
            $this->delegated->fresh(),
            $this->application->id,
            ApplicationAdminService::ACCESS_MANAGE_PERMISSION,
        ));

        $this->expectException(AuthorizationException::class);
        $this->service->assign(
            $this->application->id,
            $this->delegated->fresh(),
            $this->outsider->email,
            $profile->id,
        );
    }
}
