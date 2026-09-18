<?php

namespace App\Observers;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\ProducerAgreementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventSalesReadinessObserver
{
    public function saving(Event $event): void
    {
        if (! $event->exists || ! $event->isDirty('is_published') || ! $event->is_published) {
            return;
        }

        $hasPaidTicket = Ticket::query()
            ->where('app_id', $event->app_id)
            ->where('event_id', $event->id)
            ->where('price', '>', 0)
            ->where('quantity', '>', 0)
            ->exists();

        if (! $hasPaidTicket) {
            return;
        }

        $version = app(ProducerAgreementService::class)->version();

        $agreementSigned = DB::table('contract_acceptances')
            ->where('app_id', $event->app_id)
            ->where('production_id', $event->production_id)
            ->where('contract_version', $version)
            ->exists();

        if (! $agreementSigned) {
            throw ValidationException::withMessages([
                'agreement' => ['Assine o contrato vigente antes de publicar um evento com ingressos pagos.'],
            ]);
        }

        $ownerUserId = DB::table('establishments')
            ->where('id', $event->production_id)
            ->value('user_id');

        $payoutReady = $ownerUserId && DB::table('financial_payout_destinations as destination')
            ->join('financial_beneficiaries as beneficiary', 'beneficiary.id', '=', 'destination.beneficiary_id')
            ->where('destination.source_type', 'production')
            ->where('destination.source_id', $event->production_id)
            ->whereIn('destination.status', ['active', 'cooling'])
            ->whereNotNull('destination.verified_at')
            ->where('beneficiary.user_id', $ownerUserId)
            ->where('beneficiary.status', 'verified')
            ->exists();

        if (! $payoutReady) {
            throw ValidationException::withMessages([
                'payout' => ['Conclua a verificação financeira e cadastre uma chave Pix válida antes de publicar um evento com ingressos pagos.'],
            ]);
        }
    }
}
