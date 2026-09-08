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

        $request->attributes->set(
            'admin_authority',
            $this->service->isOwner($user, $applicationId) ? 'owner' : 'delegated_admin',
        );
        $request->attributes->set('admin_scope', 'global_application');

        return $next($request);
    }
}
