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

            abort_unless($signed, 428, 'Antes de criar o primeiro evento desta organização, leia e assine o termo de adesão.');
        }

        return $next($request);
    }
}
