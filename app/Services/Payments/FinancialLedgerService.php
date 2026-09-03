<?php

namespace App\Services\Payments;

use App\Models\EcosystemPayment;
use App\Models\FinancialLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FinancialLedgerService
{
    public function capture(EcosystemPayment $payment): void
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return;
        }

        $status = strtolower((string) $payment->status);
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        $this->record($payment, 'checkout_created', $payment->created_at, 0, 0, 0, 0, [
            'attempted_gross' => (float) $payment->gross_amount,
            'method' => $payment->method,
        ]);

        if (! empty($metadata['qr_code']) || ! empty($metadata['ticket_url'])) {
            $this->record($payment, 'qr_generated', $payment->created_at, 0, 0, 0, 0, [
                'attempted_gross' => (float) $payment->gross_amount,
            ]);
        }

        if ($payment->paid_at) {
            $this->record(
                $payment,
                'payment_confirmed',
                $payment->paid_at,
                (float) $payment->gross_amount,
                (float) $payment->platform_fee,
                (float) $payment->provider_fee,
                (float) $payment->seller_net,
            );
        }

        if (in_array($status, ['refunded', 'charged_back'], true) && $payment->refunded_at) {
            $event = $status === 'charged_back' ? 'payment_chargeback' : 'payment_refunded';
            $this->record(
                $payment,
                $event,
                $payment->refunded_at,
                -1 * (float) $payment->gross_amount,
                -1 * (float) $payment->platform_fee,
                -1 * (float) $payment->provider_fee,
                -1 * (float) $payment->seller_net,
            );
        }

        if (in_array($status, ['failed', 'rejected', 'cancelled', 'expired'], true)) {
            $this->record($payment, 'payment_' . $status, $payment->failed_at ?: $payment->updated_at, 0, 0, 0, 0, [
                'attempted_gross' => (float) $payment->gross_amount,
            ]);
        }
    }

    public function recordPayout(array $context): void
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return;
        }

        $amount = round(abs((float) ($context['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return;
        }

        $idempotency = (string) ($context['idempotency_key'] ?? 'payout:' . ($context['source_type'] ?? 'external') . ':' . ($context['source_reference'] ?? Str::uuid()));

        FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => $idempotency],
            [
                'public_id' => (string) Str::uuid(),
                'payment_id' => $context['payment_id'] ?? null,
                'app_id' => $context['app_id'] ?? null,
                'app_slug' => (string) ($context['app_slug'] ?? 'ecosystem'),
                'establishment_id' => $context['establishment_id'] ?? null,
                'production_id' => $context['production_id'] ?? null,
                'provider' => $context['provider'] ?? null,
                'provider_payment_id' => $context['provider_payment_id'] ?? null,
                'event_type' => 'seller_payout',
                'currency' => (string) ($context['currency'] ?? 'BRL'),
                'gross_amount' => 0,
                'platform_amount' => 0,
                'provider_amount' => 0,
                'seller_amount' => -$amount,
                'occurred_at' => $context['occurred_at'] ?? now(),
                'source_type' => $context['source_type'] ?? 'payout',
                'source_reference' => isset($context['source_reference']) ? (string) $context['source_reference'] : null,
                'metadata' => $context['metadata'] ?? null,
            ]
        );
    }

    public function recordAdjustment(array $context): FinancialLedgerEntry
    {
        abort_unless(Schema::hasTable('financial_ledger_entries'), 503, 'Ledger financeiro indisponível.');

        $idempotency = (string) ($context['idempotency_key'] ?? 'adjustment:' . Str::uuid());

        return FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => $idempotency],
            [
                'public_id' => (string) Str::uuid(),
                'payment_id' => $context['payment_id'] ?? null,
                'app_id' => $context['app_id'] ?? null,
                'app_slug' => (string) ($context['app_slug'] ?? 'ecosystem'),
                'establishment_id' => $context['establishment_id'] ?? null,
                'production_id' => $context['production_id'] ?? null,
                'provider' => $context['provider'] ?? null,
                'provider_payment_id' => $context['provider_payment_id'] ?? null,
                'event_type' => 'manual_adjustment',
                'currency' => (string) ($context['currency'] ?? 'BRL'),
                'gross_amount' => round((float) ($context['gross_amount'] ?? 0), 2),
                'platform_amount' => round((float) ($context['platform_amount'] ?? 0), 2),
                'provider_amount' => round((float) ($context['provider_amount'] ?? 0), 2),
                'seller_amount' => round((float) ($context['seller_amount'] ?? 0), 2),
                'occurred_at' => $context['occurred_at'] ?? now(),
                'source_type' => 'manual_adjustment',
                'source_reference' => $context['source_reference'] ?? null,
                'metadata' => [
                    'reason' => (string) ($context['reason'] ?? 'Ajuste administrativo'),
                    'actor_user_id' => $context['actor_user_id'] ?? null,
                ],
            ]
        );
    }

    public function snapshot(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return $this->emptySnapshot();
        }

        $query = FinancialLedgerEntry::query();
        if ($from) $query->where('occurred_at', '>=', $from);
        if ($to) $query->where('occurred_at', '<=', $to);
        $entries = $query->get();

        $confirmed = $entries->where('event_type', 'payment_confirmed');
        $reversals = $entries->whereIn('event_type', ['payment_refunded', 'payment_chargeback']);
        $payouts = $entries->where('event_type', 'seller_payout');
        $adjustments = $entries->where('event_type', 'manual_adjustment');

        $sellerObligation = round((float) $entries->sum('seller_amount'), 2);
        $platformBalance = round((float) $entries->sum('platform_amount'), 2);
        $providerFees = round((float) $entries->sum('provider_amount'), 2);
        $grossMovement = round((float) $entries->sum('gross_amount'), 2);

        return [
            'confirmed_gross' => round((float) $confirmed->sum('gross_amount'), 2),
            'reversed_gross' => round(abs((float) $reversals->sum('gross_amount')), 2),
            'platform_balance' => $platformBalance,
            'provider_fees' => $providerFees,
            'seller_payable' => max(0, $sellerObligation),
            'seller_paid' => round(abs((float) $payouts->sum('seller_amount')), 2),
            'gross_movement' => $grossMovement,
            'adjustments' => round((float) $adjustments->sum('gross_amount'), 2),
            'entries' => $entries->count(),
        ];
    }

    public function settlementSnapshot(): array
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return ['available_gross' => 0, 'settlement_pending_gross' => 0, 'available_count' => 0, 'settlement_pending_count' => 0];
        }

        $paid = EcosystemPayment::query()->whereIn('status', ['paid', 'approved'])->get();
        $available = $paid->filter(function (EcosystemPayment $payment) {
            if ($payment->available_at) {
                return $payment->available_at->lte(now());
            }

            return strtolower((string) $payment->method) === 'pix';
        });
        $pending = $paid->reject(fn (EcosystemPayment $payment) => $available->contains('id', $payment->id));

        return [
            'available_gross' => round((float) $available->sum('gross_amount'), 2),
            'settlement_pending_gross' => round((float) $pending->sum('gross_amount'), 2),
            'available_count' => $available->count(),
            'settlement_pending_count' => $pending->count(),
        ];
    }

    public function funnel(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return ['checkout_created' => 0, 'qr_generated' => 0, 'provider_started' => 0, 'paid' => 0, 'open' => 0, 'failed' => 0, 'conversion_rate' => 0];
        }

        $query = EcosystemPayment::query();
        if ($from) $query->where('created_at', '>=', $from);
        if ($to) $query->where('created_at', '<=', $to);
        $payments = $query->get();

        $qrGenerated = $payments->filter(function (EcosystemPayment $payment) {
            $metadata = is_array($payment->metadata) ? $payment->metadata : [];
            return ! empty($metadata['qr_code']) || ! empty($metadata['ticket_url']);
        })->count();
        $paid = $payments->whereIn('status', ['paid', 'approved'])->count();
        $total = $payments->count();

        return [
            'checkout_created' => $total,
            'qr_generated' => $qrGenerated,
            'provider_started' => $payments->filter(fn (EcosystemPayment $p) => ! empty($p->provider_payment_id))->count(),
            'paid' => $paid,
            'open' => $payments->whereIn('status', ['pending', 'in_process', 'authorized'])->count(),
            'failed' => $payments->whereIn('status', ['failed', 'rejected', 'cancelled', 'expired'])->count(),
            'conversion_rate' => $total ? round(($paid / $total) * 100, 2) : 0,
        ];
    }

    public function dailyClose(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return ['opening_balance' => 0, 'days' => [], 'closing_balance' => 0];
        }

        $openingEntries = FinancialLedgerEntry::query()->where('occurred_at', '<', $from)->get();
        $opening = $this->cashBalance($openingEntries);
        $entries = FinancialLedgerEntry::query()->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at')->get();
        $groups = $entries->groupBy(fn (FinancialLedgerEntry $entry) => $entry->occurred_at->format('Y-m-d'));

        $running = $opening;
        $days = collect();
        for ($cursor = $from->startOfDay(); $cursor->lte($to->startOfDay()); $cursor = $cursor->addDay()) {
            $day = $cursor->format('Y-m-d');
            $group = $groups->get($day, collect());
            $receipts = round((float) $group->where('event_type', 'payment_confirmed')->sum('gross_amount'), 2);
            $reversals = round(abs((float) $group->whereIn('event_type', ['payment_refunded', 'payment_chargeback'])->sum('gross_amount')), 2);
            $gatewayFees = round((float) $group->where('event_type', 'payment_confirmed')->sum('provider_amount') + (float) $group->whereIn('event_type', ['payment_refunded', 'payment_chargeback'])->sum('provider_amount'), 2);
            $payouts = round(abs((float) $group->where('event_type', 'seller_payout')->sum('seller_amount')), 2);
            $adjustments = round((float) $group->where('event_type', 'manual_adjustment')->sum('gross_amount'), 2);
            $movement = round($receipts - $reversals - $gatewayFees - $payouts + $adjustments, 2);
            $openingDay = $running;
            $running = round($running + $movement, 2);

            $days->push([
                'day' => $day,
                'opening_balance' => $openingDay,
                'receipts' => $receipts,
                'reversals' => $reversals,
                'gateway_fees' => $gatewayFees,
                'payouts' => $payouts,
                'adjustments' => $adjustments,
                'movement' => $movement,
                'closing_balance' => $running,
                'platform_revenue' => round((float) $group->sum('platform_amount'), 2),
            ]);
        }

        return ['opening_balance' => $opening, 'days' => $days->values(), 'closing_balance' => $running];
    }

    private function record(
        EcosystemPayment $payment,
        string $eventType,
        mixed $occurredAt,
        float $gross,
        float $platform,
        float $provider,
        float $seller,
        array $metadata = [],
    ): void {
        $key = "payment:{$payment->id}:{$eventType}";

        FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'app_id' => $payment->app_id,
                'app_slug' => $payment->app_slug,
                'establishment_id' => $payment->establishment_id,
                'production_id' => $payment->production_id,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'event_type' => $eventType,
                'currency' => $payment->currency ?: 'BRL',
                'gross_amount' => round($gross, 2),
                'platform_amount' => round($platform, 2),
                'provider_amount' => round($provider, 2),
                'seller_amount' => round($seller, 2),
                'occurred_at' => $occurredAt ?: now(),
                'source_type' => $payment->source_type,
                'source_reference' => $payment->source_reference,
                'metadata' => $metadata ?: null,
            ]
        );
    }

    private function cashBalance(Collection $entries): float
    {
        $receipts = (float) $entries->where('event_type', 'payment_confirmed')->sum('gross_amount');
        $reversals = abs((float) $entries->whereIn('event_type', ['payment_refunded', 'payment_chargeback'])->sum('gross_amount'));
        $gatewayFees = (float) $entries->sum('provider_amount');
        $payouts = abs((float) $entries->where('event_type', 'seller_payout')->sum('seller_amount'));
        $adjustments = (float) $entries->where('event_type', 'manual_adjustment')->sum('gross_amount');

        return round($receipts - $reversals - $gatewayFees - $payouts + $adjustments, 2);
    }

    private function emptySnapshot(): array
    {
        return [
            'confirmed_gross' => 0,
            'reversed_gross' => 0,
            'platform_balance' => 0,
            'provider_fees' => 0,
            'seller_payable' => 0,
            'seller_paid' => 0,
            'gross_movement' => 0,
            'adjustments' => 0,
            'entries' => 0,
        ];
    }
}
