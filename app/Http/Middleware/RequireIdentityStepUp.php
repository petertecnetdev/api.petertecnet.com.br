<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityStepUpService;
use Closure;
use Illuminate\Http\Request;

class RequireIdentityStepUp
{
    public function __construct(
        private readonly IdentityStepUpService $stepUp,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action = 'critical'): mixed
    {
        $user = $request->user('api');
        if (! $user) {
            return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Autenticação necessária.'], 401);
        }

        $grant = $this->stepUp->validate($user, $request, $action);
        if (! $grant) {
            $this->audit->record('step_up_required', $user, $request, null, ['action' => $action]);
            return response()->json([
                'success' => false,
                'code' => 'STEP_UP_REQUIRED',
                'message' => 'Confirme sua identidade para continuar esta operação sensível.',
                'action' => $this->stepUp->normalizeAction($action),
            ], 428);
        }

        $request->attributes->set('identity_step_up_grant', $grant);
        return $next($request);
    }
}
