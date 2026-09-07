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
        $applicationSlug = strtolower(trim((string) $request->route('application')));

        // A administração da Cutinapp é deliberadamente root-only. Perfis administrativos
        // delegados continuam funcionando nas demais aplicações do ecossistema.
        if ($applicationSlug === 'cutinapp' && (! $user || ! $this->service->isRoot($user))) {
            return response()->json([
                'success' => false,
                'message' => 'O Admin Center da Cutinapp é exclusivo da Peter Tecnet.',
                'code' => 'CUTINAPP_ADMIN_ROOT_ONLY',
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
