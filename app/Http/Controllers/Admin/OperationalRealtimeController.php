<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalRealtimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('operations_view') ||
            $user->hasPermission('security_view')
        );
        abort_unless($allowed, 403, 'Usuário sem permissão para o canal operacional em tempo real.');

        $app = (array) config('reverb.apps.apps.0', []);
        $options = (array) ($app['options'] ?? []);
        $key = (string) ($app['key'] ?? '');
        $host = (string) ($options['host'] ?? config('reverb.servers.reverb.hostname') ?? '');
        $scheme = (string) ($options['scheme'] ?? 'https');
        $port = (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80));

        return response()->json([
            'enabled' => $key !== '' && $host !== '',
            'key' => $key,
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'channel' => 'private-ecosystem.admin',
            'event' => 'mission-control.updated',
            'events' => [
                'mission-control.updated',
                'ecosystem.updated',
            ],
            'auth_endpoint' => rtrim((string) config('app.url'), '/') . '/broadcasting/auth',
        ]);
    }
}
