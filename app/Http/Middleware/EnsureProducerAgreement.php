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

        $effectiveUser = $request->user();
        $actorUser = $request->attributes->get('actor_user');
        $impersonationSession = $request->attributes->get('impersonation_session');

        $isPlatformAdmin = static function ($user): bool {
            if (! $user) return false;

            return (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'))
                || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
        };

        /*
         * Assisted onboarding may prepare an event as a draft while an audited
         * administrator is impersonating the producer. This does NOT sign the
         * agreement on behalf of the producer and does not allow publication.
         *
         * HandleImpersonation guarantees that actor/effective user and every
         * mutation are audited. We only bypass this middleware for Event
         * Management "store"; publish continues through the agreement gate.
         */
        $isAssistedDraftCreation = $impersonationSession
            && $isPlatformAdmin($actorUser)
            && $request->route()?->getActionMethod() === 'store';

        if ($isAssistedDraftCreation) {
            $request->attributes->set('assisted_producer_setup', true);

            return $next($request);
        }

        // A platform administrator acting as themself keeps the legacy setup
        // capability for draft creation, but publication must still require
        // the producer's own agreement.
        $isDirectAdminDraftCreation = ! $impersonationSession
            && $isPlatformAdmin($effectiveUser)
            && $request->route()?->getActionMethod() === 'store';

        if ($isDirectAdminDraftCreation) {
            $request->attributes->set('assisted_producer_setup', true);

            return $next($request);
        }

        $organizationId = (int) $request->input('production_id', 0);

        if ($organizationId <= 0) {
            $eventId = (int) ($request->route('id') ?? $request->route('event') ?? 0);
            if ($eventId > 0) {
                $organizationId = (int) DB::table('events')
                    ->where('id', $eventId)
                    ->where('app_id', $this->context->id())
                    ->value('production_id');
            }
        }

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

            abort_unless(
                $signed,
                428,
                'O rascunho pode ser preparado com suporte da Peter Tecnet, mas o responsável da organização precisa assinar o termo de adesão antes da publicação e das vendas.'
            );
        }

        return $next($request);
    }
}
