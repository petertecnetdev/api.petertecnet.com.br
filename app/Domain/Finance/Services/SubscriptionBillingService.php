<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Models\Application;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SubscriptionBillingService
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPago,
        private readonly ApplicationContext $context,
        private readonly SubscriptionEntitlementService $entitlements,
    ) {}

    public function createPixCheckout(SubscriptionIntent $intent, string $idempotencyKey): array
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        if ($token === '') {
            throw new RuntimeException('O checkout PIX está temporariamente indisponível.');
        }

        $application = Application::query()->where('slug', $intent->application)->where('is_active', true)->firstOrFail();
        $this->context->set($application);

        $existing = DB::table('ecosystem_payments')
            ->where('app_slug', $intent->application)
            ->where('source_type', 'subscription_intent')
            ->where('source_reference', $intent->public_id)
            ->first();

        if ($existing && $existing->provider_payment_id) {
            return $this->checkoutResponse($intent, $existing);
        }

        $user = $intent->user()->firstOrFail();
        $amount = round(((int) $intent->price_cents) / 100, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Este plano não exige checkout pago.');
        }

        $payload = [
            'transaction_amount' => $amount,
            'description' => $intent->plan_name.' - assinatura '.$intent->application,
            'payment_method_id' => 'pix',
            'external_reference' => (string) $intent->public_id,
            'notification_url' => route('finance.subscription-payments.webhook'),
            'payer' => ['email' => (string) $user->email],
            'metadata' => [
                'source_type' => 'subscription_intent',
                'subscription_intent_id' => (string) $intent->public_id,
                'application' => (string) $intent->application,
            ],
        ];

        $remote = $this->mercadoPago->createPayment($token, $payload, 'subscription-'.$intent->public_id.'-'.$idempotencyKey);
        $providerId = trim((string) ($remote['id'] ?? ''));
        if ($providerId === '') {
            throw new RuntimeException('O provedor não retornou um identificador para o pagamento.');
        }

        $transactionData = (array) data_get($remote, 'point_of_interaction.transaction_data', []);
        $now = now();
        $paymentId = DB::table('ecosystem_payments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $application->getKey(),
            'app_slug' => $intent->application,
            'provider' => 'mercadopago',
            'provider_payment_id' => $providerId,
            'source_type' => 'subscription_intent',
            'source_reference' => $intent->public_id,
            'source_id' => $intent->getKey(),
            'user_id' => $intent->user_id,
            'currency' => $intent->currency,
            'method' => 'pix',
            'status' => (string) ($remote['status'] ?? 'pending'),
            'gross_amount' => $amount,
            'platform_fee' => 0,
            'provider_fee' => 0,
            'seller_net' => $amount,
            'metadata' => json_encode([
                'idempotency_key_hash' => hash('sha256', $idempotencyKey),
                'status_detail' => $remote['status_detail'] ?? null,
                'qr_code' => $transactionData['qr_code'] ?? null,
                'qr_code_base64' => $transactionData['qr_code_base64'] ?? null,
                'ticket_url' => $transactionData['ticket_url'] ?? null,
                'date_of_expiration' => $remote['date_of_expiration'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $intent->forceFill([
            'status' => 'payment_pending',
            'checkout_started_at' => $intent->checkout_started_at ?: $now,
            'payment_pending_at' => $now,
        ])->save();

        return $this->checkoutResponse($intent->fresh(), DB::table('ecosystem_payments')->find($paymentId));
    }

    public function reconcileProviderPayment(string $providerPaymentId): ?array
    {
        $payment = DB::table('ecosystem_payments')
            ->where('provider', 'mercadopago')
            ->where('provider_payment_id', $providerPaymentId)
            ->where('source_type', 'subscription_intent')
            ->first();

        if (! $payment) {
            return null;
        }

        $application = Application::query()->whereKey($payment->app_id)->where('is_active', true)->firstOrFail();
        $this->context->set($application);
        $token = trim((string) config('services.mercadopago.access_token'));
        if ($token === '') {
            throw new RuntimeException('Token do provedor da plataforma não configurado.');
        }

        $remote = $this->mercadoPago->getPayment($token, $providerPaymentId);
        $this->applyRemoteState((int) $payment->id, $remote);

        return $this->status((string) $payment->source_reference, (int) $payment->user_id, (string) $payment->app_slug);
    }

    public function status(string $intentPublicId, int $userId, string $application): array
    {
        $intent = SubscriptionIntent::query()
            ->where('public_id', $intentPublicId)
            ->where('application', $application)
            ->where('user_id', $userId)
            ->firstOrFail();

        $payment = DB::table('ecosystem_payments')
            ->where('app_slug', $application)
            ->where('source_type', 'subscription_intent')
            ->where('source_reference', $intentPublicId)
            ->first();

        $subscription = DB::table('ecosystem_subscriptions')
            ->where('app_id', $payment?->app_id)
            ->where('user_id', $userId)
            ->first();

        $entitlements = $subscription
            ? $this->entitlements->activeForSubscription((int) $subscription->id)
            : [];
        $applicationAccess = collect($entitlements)->firstWhere('key', 'application_access');

        return [
            'intent' => ['id' => $intent->public_id, 'status' => $intent->status, 'plan_code' => $intent->plan_code],
            'payment' => $payment ? ['id' => $payment->public_id, 'status' => $payment->status, 'method' => $payment->method] : null,
            'subscription' => $subscription ? [
                'id' => $subscription->public_id,
                'status' => $subscription->status,
                'plan_code' => $subscription->plan_code,
                'current_period_end' => $subscription->current_period_end,
            ] : null,
            'entitlement' => $applicationAccess,
            'entitlements' => $entitlements,
        ];
    }

    private function applyRemoteState(int $paymentId, array $remote): void
    {
        DB::transaction(function () use ($paymentId, $remote) {
            $payment = DB::table('ecosystem_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                throw new RuntimeException('Pagamento local não encontrado.');
            }

            $remoteId = (string) ($remote['id'] ?? '');
            $externalReference = (string) ($remote['external_reference'] ?? '');
            $amount = round((float) ($remote['transaction_amount'] ?? 0), 2);
            if ($remoteId !== (string) $payment->provider_payment_id || $externalReference !== (string) $payment->source_reference) {
                throw new RuntimeException('Pagamento remoto não corresponde à assinatura local.');
            }
            if (abs($amount - (float) $payment->gross_amount) > 0.009) {
                throw new RuntimeException('Valor confirmado pelo provedor é diferente da assinatura.');
            }

            $intent = SubscriptionIntent::query()->whereKey($payment->source_id)->lockForUpdate()->firstOrFail();
            $status = (string) ($remote['status'] ?? 'pending');
            $now = now();

            if ($status === 'approved') {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update([
                    'status' => 'paid', 'paid_at' => $payment->paid_at ?: $now, 'failed_at' => null, 'updated_at' => $now,
                ]);

                $periodEnd = match ($intent->billing_interval) {
                    'year' => $now->copy()->addYears(max(1, (int) $intent->billing_interval_count)),
                    'week' => $now->copy()->addWeeks(max(1, (int) $intent->billing_interval_count)),
                    'day' => $now->copy()->addDays(max(1, (int) $intent->billing_interval_count)),
                    default => $now->copy()->addMonthsNoOverflow(max(1, (int) $intent->billing_interval_count)),
                };

                $subscription = DB::table('ecosystem_subscriptions')->where('app_id', $payment->app_id)->where('user_id', $payment->user_id)->lockForUpdate()->first();
                $values = [
                    'subscription_intent_id' => $intent->getKey(), 'plan_code' => $intent->plan_code, 'status' => 'active',
                    'currency' => $intent->currency, 'price_cents' => $intent->price_cents,
                    'billing_interval' => $intent->billing_interval, 'billing_interval_count' => $intent->billing_interval_count,
                    'current_period_start' => $now, 'current_period_end' => $periodEnd, 'cancelled_at' => null, 'updated_at' => $now,
                ];
                if ($subscription) {
                    DB::table('ecosystem_subscriptions')->where('id', $subscription->id)->update($values);
                    $subscriptionId = (int) $subscription->id;
                } else {
                    $subscriptionId = (int) DB::table('ecosystem_subscriptions')->insertGetId($values + [
                        'public_id' => (string) Str::uuid(), 'app_id' => $payment->app_id, 'user_id' => $payment->user_id, 'created_at' => $now,
                    ]);
                }

                $this->entitlements->syncForPlan(
                    (int) $payment->app_id,
                    (int) $payment->user_id,
                    $subscriptionId,
                    (string) $intent->application,
                    (string) $intent->plan_code,
                    $now,
                    $periodEnd,
                );

                $intent->forceFill(['status' => 'active', 'paid_at' => $intent->paid_at ?: $now, 'activated_at' => $intent->activated_at ?: $now])->save();
                return;
            }

            if (in_array($status, ['rejected', 'cancelled'], true)) {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update(['status' => $status, 'failed_at' => $now, 'updated_at' => $now]);
                if ($intent->status !== 'active') {
                    $intent->forceFill(['status' => 'payment_failed'])->save();
                }
                return;
            }

            if (in_array($status, ['refunded', 'charged_back'], true)) {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update(['status' => $status, 'refunded_at' => $now, 'updated_at' => $now]);
                DB::table('ecosystem_subscriptions')->where('app_id', $payment->app_id)->where('user_id', $payment->user_id)->update(['status' => 'suspended', 'updated_at' => $now]);
                DB::table('ecosystem_entitlements')->where('app_id', $payment->app_id)->where('user_id', $payment->user_id)->update(['status' => 'inactive', 'expires_at' => $now, 'updated_at' => $now]);
                $intent->forceFill(['status' => 'reversed'])->save();
                return;
            }

            DB::table('ecosystem_payments')->where('id', $payment->id)->update(['status' => $status ?: 'pending', 'updated_at' => $now]);
        });
    }

    private function checkoutResponse(SubscriptionIntent $intent, object $payment): array
    {
        $metadata = json_decode((string) ($payment->metadata ?? '{}'), true) ?: [];
        return [
            'intent' => ['id' => $intent->public_id, 'status' => $intent->status, 'plan_code' => $intent->plan_code],
            'payment' => [
                'id' => $payment->public_id,
                'status' => $payment->status,
                'method' => $payment->method,
                'amount' => (float) $payment->gross_amount,
                'currency' => $payment->currency,
                'pix' => [
                    'qr_code' => $metadata['qr_code'] ?? null,
                    'qr_code_base64' => $metadata['qr_code_base64'] ?? null,
                    'ticket_url' => $metadata['ticket_url'] ?? null,
                    'expires_at' => $metadata['date_of_expiration'] ?? null,
                ],
            ],
        ];
    }
}
