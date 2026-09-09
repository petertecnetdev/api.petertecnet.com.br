<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Services\AppNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomatedCheckoutRecoveryService
{
    public function __construct(
        private readonly AppNotificationService $notifications,
        private readonly PendingCheckoutRecoveryService $recovery,
    ) {
    }

    /**
     * Dispatch one zero-marginal-cost in-app reminder per recoverable PIX checkout.
     *
     * The existing recovery_started_at field is the idempotency guard: once an order
     * is attributed to a recovery attempt it will not be selected again by this job.
     * E-mail is explicitly disabled here so this automation cannot create provider
     * spend or message users outside the application.
     *
     * @return array{eligible:int,dispatched:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        if (! (bool) config('checkout_recovery.automated_in_app.enabled', true)) {
            return ['eligible' => 0, 'dispatched' => 0, 'failed' => 0];
        }

        $delayMinutes = max((int) config('checkout_recovery.automated_in_app.delay_minutes', 5), 1);
        $minimumRemainingMinutes = max((int) config('checkout_recovery.automated_in_app.minimum_remaining_minutes', 5), 1);
        $limit = min(max($limit ?? (int) config('checkout_recovery.automated_in_app.batch_limit', 100), 1), 500);

        $orders = CommerceOrder::query()
            ->where('status', 'pending')
            ->where('payment_method', 'pix')
            ->whereNull('recovery_started_at')
            ->whereNotNull('user_id')
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->where('expires_at', '>', now()->addMinutes($minimumRemainingMinutes))
            ->whereHas('application', function ($query) {
                $query->where('is_active', true)
                    ->whereNotIn('operational_status', ['maintenance', 'down']);
            })
            ->whereHas('payments', function ($query) {
                $query->where('provider', 'mercadopago')
                    ->whereIn('status', ['pending', 'in_process']);
            })
            ->with('application:id,url')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $applicationUrl = rtrim(trim((string) $order->application?->url), '/');
                if (! filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
                    $failed++;
                    continue;
                }

                $this->notifications->sendToUser((int) $order->app_id, (int) $order->user_id, [
                    'type' => 'checkout_recovery',
                    'title' => 'Seu pagamento PIX ainda está pendente',
                    'message' => 'Se quiser concluir seu pedido, ele continua disponível até o vencimento.',
                    'reference_type' => 'commerce_order',
                    'reference_id' => (string) ($order->public_id ?: $order->id),
                    'reference_url' => $applicationUrl,
                    'data' => [
                        'order_public_id' => $order->public_id,
                        'payment_expires_at' => $order->expires_at?->toIso8601String(),
                        'recovery_channel' => 'in_app',
                    ],
                    'send_email' => false,
                ]);

                $recovered = $this->recovery->recover(
                    (int) $order->app_id,
                    (int) $order->user_id,
                    (int) $order->id,
                    'in_app',
                    0.0,
                );

                if ($recovered) {
                    $dispatched++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Falha ao disparar recuperação in-app de checkout.', [
                    'order_id' => $order->id,
                    'app_id' => $order->app_id,
                    'user_id' => $order->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'eligible' => $orders->count(),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];
    }
}
