<?php

namespace App\Domain\Engagement\Services;

use App\Models\EcosystemAuditLog;
use Illuminate\Validation\ValidationException;

final class CommunicationTrackingService
{
    public function trackClick(string $communicationId, string $encodedTarget, string $cta, ?int $entityId, ?int $appId, ?string $ip, ?string $userAgent): string
    {
        $target = $this->decodeTarget($encodedTarget);
        if ($target === null || ! $this->isAllowedTarget($target)) {
            throw ValidationException::withMessages(['target' => ['Destino inválido.']]);
        }

        $this->audit('communication.engagement.clicked', $communicationId, $entityId, $appId, $ip, $userAgent, [
            'cta' => $cta,
            'target' => $target,
        ]);
        return $target;
    }

    public function trackOpen(string $communicationId, ?int $entityId, ?int $appId, ?string $ip, ?string $userAgent): void
    {
        $this->audit('communication.engagement.opened', $communicationId, $entityId, $appId, $ip, $userAgent);
    }

    private function audit(string $action, string $communicationId, ?int $entityId, ?int $appId, ?string $ip, ?string $userAgent, array $extra = []): void
    {
        EcosystemAuditLog::query()->create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => 'communication',
            'entity_id' => $entityId ?: null,
            'before' => null,
            'after' => array_merge(['communication_id'=>$communicationId,'app_id'=>$appId ?: null], $extra),
            'ip' => $ip,
            'user_agent' => substr((string) $userAgent, 0, 1000),
        ]);
    }

    private function decodeTarget(string $encoded): ?string
    {
        if ($encoded === '') return null;
        $encoded = strtr($encoded, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding) $encoded .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($encoded, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function isAllowedTarget(string $target): bool
    {
        $parts = parse_url($target);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $host === 'petertecnet.com.br' || str_ends_with($host, '.petertecnet.com.br');
    }
}
