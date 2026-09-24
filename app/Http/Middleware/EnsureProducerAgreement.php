<?php

namespace App\Http\Middleware;

use App\Services\ProducerAgreementService;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EnsureProducerAgreement
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! (bool) $this->context->option('events.requires_producer_agreement', false)) {
            return $next($request);
        }

        // Administrative impersonation is an assisted onboarding/support session.
        // The administrator may prepare the customer's production and events, but
        // the customer's legal acceptance must remain untouched and be completed
        // later by the account owner. HandleImpersonation validates the session,
        // binds it to the effective user/application and audits every request.
        if ($request->attributes->get('impersonation_session')) {
            return $next($request);
        }

        $user = $request->user();
        $isAssistedAdmin = $user && (
            (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'))
            || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com'
        );

        if ($isAssistedAdmin) {
            return $next($request);
        }

        $organizationId = (int) $request->input('production_id', 0);
        if ($organizationId > 0) {
            $belongsToApplication = DB::table('productions')
                ->where('id', $organizationId)
                ->where('app_id', $this->context->id())
                ->exists();

            abort_unless($belongsToApplication, 404, 'Organização não encontrada neste contexto.');

            $signed = DB::table('contract_acceptances')
                ->where('app_id', $this->context->id())
                ->where('production_id', $organizationId)
                ->where('contract_version', $this->agreements->version())
                ->exists();

            abort_unless($signed, 428, 'Antes de criar novos eventos desta organização, leia e assine o termo de adesão.');
        }

        return $next($request);
    }
}
