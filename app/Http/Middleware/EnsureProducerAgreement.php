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

        // Only a validated impersonation session may bypass the customer's legal
        // acceptance during assisted onboarding. Administrator roles or specific
        // email addresses must never bypass this requirement while acting as
        // themselves. HandleImpersonation validates and audits the session before
        // this attribute is made available to downstream middleware.
        if ($request->attributes->get('impersonation_session')) {
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
