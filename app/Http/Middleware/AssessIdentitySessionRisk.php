<?php

namespace App\Http\Middleware;

use App\Services\IdentityRiskService;
use App\Services\IdentitySessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssessIdentitySessionRisk
{
    public function __construct(
        private readonly IdentitySessionService $identity,
        private readonly IdentityRiskService $risk
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->identity->resolve($request);
        if (! $session) {
            return $next($request);
        }

        $assessment = $this->risk->assess($request, $session);
        if (! empty($assessment['signals'])) {
            $this->identity->audit(
                $request,
                'session_risk_detected',
                $assessment['level'] === 'high' ? 'challenge_required' : 'observed',
                $session->user,
                $session,
                $session->lastApplication,
                $assessment
            );
        }

        if ($assessment['level'] === 'high') {
            $this->identity->revoke($session, 'high_risk_session_context', $request);
            return response()->json([
                'success' => false,
                'message' => 'Detectamos uma mudança importante no contexto desta sessão. Faça login novamente para sua segurança.',
                'code' => 'IDENTITY_REAUTH_REQUIRED',
            ], 401)->withCookies($this->identity->forgetCookies());
        }

        $this->risk->remember($request, $session, $assessment);
        return $next($request);
    }
}
