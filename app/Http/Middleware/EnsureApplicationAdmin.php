<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Services\ApplicationAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationAdmin
{
    public function __construct(private readonly ApplicationAdminService $service)
    {
    }

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user('api') ?? $request->user();
        $applicationId = (int) $request->attributes->get('app_id');
        $applicationSlug = strtolower(trim((string) $request->attributes->get('application_slug')));

        // Esta aplicação mantém a administração operacional exclusivamente na conta raiz.
        // As demais aplicações continuam usando os perfis administrativos delegáveis existentes.
        if ($applicationSlug === 'cutinapp' && (! $user || ! $this->service->isRoot($user))) {
            return response()->json([
                'success' => false,
                'message' => 'O Admin Center desta aplicação é exclusivo da Peter Tecnet.',
                'code' => 'APPLICATION_ADMIN_ROOT_ONLY',
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        $authorized = $user && $applicationId > 0 && (
            $permission
                ? $this->service->hasPermission($user, $applicationId, $permission)
                : $this->service->hasAccess($user, $applicationId)
        );

        if (! $authorized) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso administrativo não autorizado para esta aplicação.',
                'code' => 'APPLICATION_ADMIN_FORBIDDEN',
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        return $next($request);
    }
}
