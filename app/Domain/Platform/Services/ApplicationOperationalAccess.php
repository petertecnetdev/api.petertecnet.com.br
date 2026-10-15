<?php

namespace App\Domain\Platform\Services;

use App\Models\User;
use App\Support\ApplicationContext;

/**
 * Adapta permissões administrativas da aplicação aos endpoints legados.
 * Falha fechada fora do contexto confiável da Cutinapp.
 */
final class ApplicationOperationalAccess
{
    private const ACTIONS = [
        'production_show' => 'establishments.view',
        'production_create' => 'establishments.manage',
        'production_edit' => 'establishments.manage',
        'production_update' => 'establishments.manage',
        'production_delete' => 'establishments.manage',
        'production_config' => 'establishments.manage',
        'event_create' => 'events.manage',
        'event_edit' => 'events.manage',
        'event_update' => 'events.manage',
        'event_delete' => 'events.manage',
        'event_config' => 'events.manage',
        'ticket_create' => 'tickets.manage',
        'ticket_edit' => 'tickets.manage',
        'ticket_update' => 'tickets.manage',
        'ticket_delete' => 'tickets.manage',
        'ticket_checkin' => 'checkin.manage',
        'event_checkin' => 'checkin.manage',
        'artist_curate' => 'artists.manage',
    ];

    public function allows(User $user, string $action, ?int $applicationId = null): bool
    {
        $adminPermission = self::ACTIONS[$action] ?? null;
        if (! $adminPermission) {
            return false;
        }

        $context = app(ApplicationContext::class);
        if (! $context->has() || ! $context->option('operational_admin_access', false)) {
            return false;
        }

        $resolvedId = $context->id();
        if ($applicationId !== null && $applicationId !== $resolvedId) {
            return false;
        }

        return app(ApplicationAdminService::class)->hasPermission($user, $resolvedId, $adminPermission);
    }
}
