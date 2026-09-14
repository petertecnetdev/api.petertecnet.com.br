<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\EventPass;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PaidTicketFulfillmentRecoveryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
    ) {
    }

    public function recover(CommerceOrder $order, string $source = 'manual'): array
    {
        abort_unless((int) $order->app_id === $this->context->id(), 404);
        if ($order->status !== 'paid') {
            throw ValidationException::withMessages(['order' => 'Somente pedidos confirmados como pagos podem ter a entrega reprocessada.']);
        }

        $source = in_array($source, ['manual', 'automatic'], true) ? $source : 'manual';
        $order->loadMissing(['items', 'payments', 'user']);
        $ticketItems = $order->items->where('type', 'ticket');
        $expected = (int) $ticketItems->sum(fn ($item) => (int) $item->quantity);
        $itemIds = $ticketItems->pluck('id');
        $issuedBefore = EventPass::query()->whereIn('commerce_order_item_id', $itemIds)->count();

        if ($expected <= 0 || $issuedBefore >= $expected) {
            return $this->result($order, $expected, $issuedBefore, $issuedBefore, false, $source);
        }

        $payment = $order->payments
            ->where('provider', 'mercadopago')
            ->whereNotNull('provider_payment_id')
            ->sortByDesc('id')
            ->first();
        if (! $payment) {
            throw ValidationException::withMessages(['payment' => 'Não foi encontrado pagamento Mercado Pago vinculado ao pedido.']);
        }

        $remote = $this->verifiedRemotePayment($order, $payment);

        $issuedAfter = DB::transaction(function () use ($order, $payment, $remote, $source) {
            $locked = CommerceOrder::query()
                ->where('app_id', $this->context->id())
                ->with(['items', 'user'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($locked->status !== 'paid') {
                throw ValidationException::withMessages(['order' => 'O pedido deixou de estar pago antes da recuperação. Nenhum ingresso foi emitido.']);
            }

            $this->assertRemoteMatchesOrder($locked, $payment, $remote);
            foreach ($locked->items->where('type', 'ticket') as $line) {
                $already = EventPass::query()->where('commerce_order_item_id', $line->id)->count();
                $toIssue = max(0, (int) $line->quantity - $already);
                for ($i = 0; $i < $toIssue; $i++) {
                    EventPass::create([
                        'event_id' => $locked->event_id,
                        'ticket_id' => $line->ticket_id,
                        'commerce_order_item_id' => $line->id,
                        'user_id' => $locked->user_id,
                        'holder_name' => trim(($locked->user->first_name ?? '').' '.($locked->user->last_name ?? '')) ?: null,
                        'holder_email' => $locked->user->email ?? null,
                        'token' => 'PASS-'.Str::upper(Str::replace('-', '', (string) Str::uuid())),
                        'status' => 'issued',
                    ]);
                }
            }

            $expected = (int) $locked->items->where('type', 'ticket')->sum(fn ($item) => (int) $item->quantity);
            $ids = $locked->items->where('type', 'ticket')->pluck('id');
            $issued = EventPass::query()->whereIn('commerce_order_item_id', $ids)->count();
            if ($issued < $expected) {
                throw new RuntimeException('A entrega continuou incompleta após o reprocessamento idempotente.');
            }

            $recoveredAt = now()->toIso8601String();
            $metadata = (array) $locked->metadata;
            $metadata['fulfillment_status'] = 'completed';
            $metadata['fulfilled_at'] = $recoveredAt;
            $metadata['fulfillment_last_attempt_at'] = $recoveredAt;
            $metadata['fulfillment_recovered_at'] = $recoveredAt;
            $metadata['fulfillment_recovery_source'] = $source;
            $metadata[$source === 'automatic' ? 'fulfillment_recovered_automatically_at' : 'fulfillment_recovered_manually_at'] = $recoveredAt;
            unset($metadata['fulfillment_error'], $metadata['fulfillment_failed_at']);
            $locked->forceFill(['metadata' => $metadata])->save();

            return $issued;
        });

        return $this->result($order, $expected, $issuedBefore, $issuedAfter, true, $source);
    }

    private function verifiedRemotePayment(CommerceOrder $order, CommercePayment $payment): array
    {
        $settlementMode = (string) data_get($order->metadata, 'settlement_mode', data_get($payment->provider_payload, 'metadata.settlement_mode', 'automatic_split'));
        if (in_array($settlementMode, ['platform_collection', 'same_account'], true)) {
            $token = trim((string) config('services.mercadopago.access_token'));
            if ($token === '') throw new RuntimeException('Token da plataforma Mercado Pago não configurado.');
        } else {
            [, $token] = $this->accounts->accessTokenForOrganization((int) $order->production_id);
        }

        $remote = $this->mercadoPago->getPayment($token, (string) $payment->provider_payment_id);
        if ((string) ($remote['status'] ?? '') !== 'approved') {
            throw ValidationException::withMessages(['payment' => 'O provedor não confirmou este pagamento como aprovado. A entrega não foi reprocessada.']);
        }
        $this->assertRemoteMatchesOrder($order, $payment, $remote);

        return $remote;
    }

    private function assertRemoteMatchesOrder(CommerceOrder $order, CommercePayment $payment, array $remote): void
    {
        $remoteId = (string) ($remote['id'] ?? '');
        $reference = (string) ($remote['external_reference'] ?? '');
        $amount = round((float) ($remote['transaction_amount'] ?? 0), 2);
        if ($remoteId === '' || $remoteId !== (string) $payment->provider_payment_id) {
            throw ValidationException::withMessages(['payment' => 'O pagamento remoto não corresponde ao pagamento local.']);
        }
        if ($reference === '' || $reference !== (string) $order->public_id) {
            throw ValidationException::withMessages(['payment' => 'A referência externa do pagamento não corresponde ao pedido.']);
        }
        if (abs($amount - (float) $order->total) > 0.009) {
            throw ValidationException::withMessages(['payment' => 'O valor aprovado no provedor é diferente do total do pedido.']);
        }

        $settlementMode = (string) data_get($order->metadata, 'settlement_mode', data_get($payment->provider_payload, 'metadata.settlement_mode', 'automatic_split'));
        if ($settlementMode === 'automatic_split' && array_key_exists('application_fee', $remote)
            && abs((float) $remote['application_fee'] - (float) $order->platform_fee) > 0.009) {
            throw ValidationException::withMessages(['payment' => 'A comissão confirmada pelo provedor é diferente da comissão do pedido.']);
        }
    }

    private function result(CommerceOrder $order, int $expected, int $before, int $after, bool $providerVerified, string $source): array
    {
        return [
            'order_public_id' => (string) $order->public_id,
            'expected_passes' => $expected,
            'emitted_before' => $before,
            'emitted_passes' => $after,
            'missing_passes' => max(0, $expected - $after),
            'recovered_passes' => max(0, $after - $before),
            'provider_verified' => $providerVerified,
            'recovery_source' => $source,
            'protected_gmv' => round((float) $order->total, 2),
            'protected_platform_revenue' => round((float) $order->platform_fee, 2),
        ];
    }
}
