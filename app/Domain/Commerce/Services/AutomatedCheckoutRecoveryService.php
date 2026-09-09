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
     * is attributed to a recovery attempt or control cohort it will not be selected
     * again by this job. E-mail is explicitly disabled here so this automation cannot
     * create provider spend or message users outside the application.
     *
     * @return array{eligible:int,dispatched:int,control:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        if (! (bool) config('checkout_recovery.automated_in_app.enabled', true)) {
            return ['eligible' => 0, 'dispatched' => 0, 'control' => 0, 'failed' => 0];
        }

        $fallbackDelay = max((int) config('checkout_recovery.automated_in_app.delay_minutes', 5), 1);
        $experimentEnabled = (bool) config('checkout_recovery.automated_in_app.timing_experiment_enabled', true);
        $experimentName = trim((string) config('checkout_recovery.automated_in_app.timing_experiment_name', 'pix_in_app_timing_v1'));
        $timingDelays = $experimentEnabled
            ? $this->timingDelays((string) config('checkout_recovery.automated_in_app.timing_delays_minutes', '5,15,30,60'), $fallbackDelay)
            : [$fallbackDelay];
        $minimumDelay = min($timingDelays);
        $minimumRemainingMinutes = max((int) config('checkout_recovery.automated_in_app.minimum_remaining_minutes', 5), 1);
        $limit = min(max($limit ?? (int) config('checkout_recovery.automated_in_app.batch_limit', 100), 1), 500);
        $controlPercent = min(max((int) config('checkout_recovery.automated_in_app.control_group_percent', 10), 0), 50);
        $scanLimit = min(max($limit * max(count($timingDelays), 1) * 2, $limit), 2000);

        $orders = CommerceOrder::query()
            ->where('status', 'pending')
            ->where('payment_method', 'pix')
            ->whereNull('recovery_started_at')
            ->whereNotNull('user_id')
            ->where('created_at', '<=', now()->subMinutes($minimumDelay))
            ->where('expires_at', '>', now()->addMinutes($minimumRemainingMinutes))
            ->whereHas('application', function ($query) {
                $query->where('is_active', true)
                    ->where(function ($operational) {
                        $operational->whereNull('operational_status')
                            ->orWhereNotIn('operational_status', ['maintenance', 'down']);
                    });
            })
            ->whereHas('payments', function ($query) {
                $query->where('provider', 'mercadopago')
                    ->whereIn('status', ['pending', 'in_process']);
            })
            ->with('application:id,url')
            ->orderBy('id')
            ->limit($scanLimit)
            ->get();

        $eligible = 0;
        $dispatched = 0;
        $control = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if (($dispatched + $control) >= $limit) {
                break;
            }

            $timingMinutes = $this->assignedTimingMinutes($order, $timingDelays, $experimentName);
            if ($order->created_at?->isAfter(now()->subMinutes($timingMinutes))) {
                continue;
            }

            $eligible++;
            $recoveryContext = $experimentEnabled ? [
                'experiment_name' => $experimentName !== '' ? $experimentName : 'pix_in_app_timing_v1',
                'timing_minutes' => $timingMinutes,
                'timing_variant' => $timingMinutes.'m',
            ] : [];

            try {
                if ($this->isControlOrder($order, $controlPercent)) {
                    $marked = $this->recovery->recover(
                        (int) $order->app_id,
                        (int) $order->user_id,
                        (int) $order->id,
                        'control',
                        0.0,
                        $recoveryContext,
                    );

                    if ($marked) {
                        $control++;
                    }

                    continue;
                }

                $applicationUrl = rtrim(trim((string) $order->application?->url), '/');
                if (! filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
                    $failed++;
                    continue;
                }

                $referenceUrl = $this->recoveryReferenceUrl($applicationUrl, (string) ($order->public_id ?: $order->id));

                $this->notifications->sendToUser((int) $order->app_id, (int) $order->user_id, [
                    'type' => 'checkout_recovery',
                    'title' => 'Seu pagamento PIX ainda está pendente',
                    'message' => 'Se quiser concluir seu pedido, ele continua disponível até o vencimento.',
                    'reference_type' => 'commerce_order',
                    'reference_id' => (string) ($order->public_id ?: $order->id),
                    'reference_url' => $referenceUrl,
                    'data' => [
                        'order_public_id' => $order->public_id,
                        'payment_expires_at' => $order->expires_at?->toIso8601String(),
                        'recovery_channel' => 'in_app',
                        'recovery_deep_link' => $referenceUrl !== $applicationUrl,
                        'recovery_experiment' => $recoveryContext['experiment_name'] ?? null,
                        'recovery_timing_minutes' => $recoveryContext['timing_minutes'] ?? null,
                    ],
                    'send_email' => false,
                ]);

                $recovered = $this->recovery->recover(
                    (int) $order->app_id,
                    (int) $order->user_id,
                    (int) $order->id,
                    'in_app',
                    0.0,
                    $recoveryContext,
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
            'eligible' => $eligible,
            'dispatched' => $dispatched,
            'control' => $control,
            'failed' => $failed,
        ];
    }

    /** @return array<int, int> */
    private function timingDelays(string $configured, int $fallbackDelay): array
    {
        $delays = collect(explode(',', $configured))
            ->map(static fn (string $value): int => (int) trim($value))
            ->filter(static fn (int $value): bool => $value > 0 && $value <= 1440)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $delays !== [] ? $delays : [$fallbackDelay];
    }

    /** @param array<int, int> $timingDelays */
    private function assignedTimingMinutes(CommerceOrder $order, array $timingDelays, string $experimentName): int
    {
        if (count($timingDelays) === 1) {
            return $timingDelays[0];
        }

        $stableKey = ($experimentName !== '' ? $experimentName : 'pix_in_app_timing_v1')
            .'|'.(string) ($order->public_id ?: $order->id);
        $bucket = ((int) sprintf('%u', crc32($stableKey))) % count($timingDelays);

        return $timingDelays[$bucket];
    }

    private function isControlOrder(CommerceOrder $order, int $controlPercent): bool
    {
        if ($controlPercent <= 0) {
            return false;
        }

        $stableKey = (string) ($order->public_id ?: $order->id);
        $bucket = ((int) sprintf('%u', crc32($stableKey))) % 100;

        return $bucket < $controlPercent;
    }

    private function recoveryReferenceUrl(string $applicationUrl, string $publicId): string
    {
        $host = strtolower((string) parse_url($applicationUrl, PHP_URL_HOST));
        $paths = (array) config('checkout_recovery.automated_in_app.deep_link_paths_by_host', []);
        $template = trim((string) ($paths[$host] ?? ''));

        // Only relative application paths are accepted. This prevents configuration
        // from turning a trusted in-app notification into an external redirect.
        if ($template === '' || ! str_starts_with($template, '/') || str_starts_with($template, '//')) {
            return $applicationUrl;
        }

        $path = str_replace('{public_id}', rawurlencode($publicId), $template);

        return str_contains($path, '{') || str_contains($path, '}')
            ? $applicationUrl
            : $path;
    }
}
