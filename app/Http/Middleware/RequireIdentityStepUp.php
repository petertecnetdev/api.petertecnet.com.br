<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\IdentityStepUpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIdentityStepUp
{
    public function __construct(private readonly IdentityStepUpService $stepUp)
    {
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $grant = $this->stepUp->consume($request, $action);
        if (! $grant) {
            return response()->json([
                'success' => false,
                'message' => 'Confirme sua identidade novamente para concluir esta ação.',
                'code' => 'STEP_UP_REQUIRED',
                'step_up_action' => $action,
            ], 428);
        }

        $request->attributes->set('identity_step_up', $grant);
        return $next($request);
    }
}
