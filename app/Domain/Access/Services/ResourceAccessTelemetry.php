<?php

namespace App\Domain\Access\Services;

use App\Models\Interaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

final class ResourceAccessTelemetry
{
    public function registerRestrictedAttempt(
        ?Model $entity,
        array $availability,
        string $resource,
        ?Authenticatable $user = null,
        ?string $ip = null,
        int $dedupeSeconds = 60
    ): void {
        if (! $entity || ! in_array($availability['status'] ?? null, ['restricted', 'unavailable'], true)) {
            return;
        }

        $userId = $user?->getAuthIdentifier();
        $ip = $ip ?: request()->ip();
        $entityType = class_basename($entity);

        $recent = Interaction::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entity->getKey())
            ->where('interaction_type', 'restricted_access')
            ->where('created_at', '>=', now()->subSeconds(max(1, $dedupeSeconds)))
            ->where(function ($query) use ($userId, $ip) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }

                if ($ip) {
                    $method = $userId ? 'orWhereJsonContains' : 'whereJsonContains';
                    $query->{$method}('content->ip', $ip);
                }
            })
            ->exists();

        if ($recent) {
            return;
        }

        Interaction::register('restricted_access', $entity, $user, [
            'resource' => $resource,
            'availability_status' => $availability['status'] ?? null,
            'availability_reason' => $availability['reason'] ?? null,
            'ip' => $ip,
        ], 'Tentativa de acesso a recurso restrito');
    }
}
