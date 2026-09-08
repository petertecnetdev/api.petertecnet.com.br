<?php

namespace App\Http\Controllers;

use App\Models\EcosystemAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationTrackingController extends Controller
{
    public function click(Request $request, string $communicationId): RedirectResponse
    {
        $target = $this->decodeTarget((string) $request->query('target', ''));
        abort_unless($target !== null && $this->isAllowedTarget($target), 400, 'Destino inválido.');

        $this->audit($request, 'communication.engagement.clicked', $communicationId, [
            'cta' => (string) $request->query('cta', ''),
            'target' => $target,
        ]);

        return redirect()->away($target);
    }

    public function open(Request $request, string $communicationId): Response
    {
        $this->audit($request, 'communication.engagement.opened', $communicationId);

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true) ?: '';

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function audit(Request $request, string $action, string $communicationId, array $extra = []): void
    {
        EcosystemAuditLog::query()->create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => 'communication',
            'entity_id' => (int) $request->query('entity_id', 0) ?: null,
            'before' => null,
            'after' => array_merge([
                'communication_id' => $communicationId,
                'app_id' => (int) $request->query('app_id', 0) ?: null,
            ], $extra),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
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
