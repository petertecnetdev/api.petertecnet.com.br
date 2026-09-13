<?php

namespace App\Domain\Finance\Services;

use App\Models\AppNotification;
use App\Models\Application;
use App\Services\AppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomatedSubscriptionRenewalRecoveryService
{
    public function __construct(private readonly AppNotificationService $notifications)
    {
    }

    /**
     * Recover paid subscriptions whose current billing period expired without renewal.
     *
     * Each billing period gets at most one reminder. The subscription row is revalidated
     * under a lock immediately before delivery so a concurrent successful renewal wins.
     *
     * @return array{eligible:int,dispatched:int,skipped:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        $limit = min(max($limit ?? 100, 1), 500);
        $now = now();
        $oldestRecoverable = $now->copy()->subDays(30);

        $subscriptions = DB::table('ecosystem_subscriptions')
            ->where('status', 'active')
            ->whereNull('cancelled_at')
            ->where('price_cents', '>', 0)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->where('current_period_end', '>=', $oldestRecoverable)
            ->orderBy('current_period_end')
            ->limit($limit * 3)
            ->get();

        $eligible = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            if ($dispatched >= $limit) {
                break;
            }

            $application = Application::query()->whereKey((int) $subscription->app_id)->first();
            if (! $application || ! $application->is_active || ! $application->isOperational()) {
                $skipped++;
                continue;
            }

            try {
                $sent = DB::transaction(function () use ($subscription, $application, $oldestRecoverable): bool {
                    $fresh = DB::table('ecosystem_subscriptions')
                        ->where('id', (int) $subscription->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $fresh || ! $this->isRecoverable($fresh, $oldestRecoverable)) {
                        return false;
                    }

                    $periodEnd = CarbonImmutable::parse((string) $fresh->current_period_end);
                    $referenceId = hash('sha256', implode('|', [
                        (string) $fresh->public_id,
                        $periodEnd->utc()->format('Y-m-d H:i:s'),
                    ]));

                    $alreadySent = AppNotification::query()
                        ->where('app_id', (int) $application->id)
                        ->where('user_id', (int) $fresh->user_id)
                        ->where('type', 'subscription_renewal_recovery')
                        ->where('reference_type', 'subscription_renewal_period')
                        ->where('reference_id', $referenceId)
                        ->exists();

                    if ($alreadySent) {
                        return false;
                    }

                    $query = http_build_query([
                        'source' => 'renewal_recovery',
                        'resume' => '1',
                        'plan' => (string) $fresh->plan_code,
                    ]);
                    $referenceUrl = rtrim((string) $application->url, '/').'/planos?'.$query;

                    $this->notifications->sendToUser((int) $application->id, (int) $fresh->user_id, [
                        'type' => 'subscription_renewal_recovery',
                        'title' => 'Seu acesso pode ser reativado agora',
                        'message' => 'Seu período de assinatura terminou. Renove o mesmo plano para restaurar o acesso sem precisar configurar tudo novamente.',
                        'reference_type' => 'subscription_renewal_period',
                        'reference_id' => $referenceId,
                        'reference_url' => $referenceUrl,
                        'data' => [
                            'subscription_public_id' => $fresh->public_id,
                            'plan_code' => $fresh->plan_code,
                            'period_ended_at' => $periodEnd->toIso8601String(),
                            'recovery_channel' => 'in_app_email',
                            'recovery_action' => 'renew_subscription',
                            'recovery_cta_label' => 'Reativar meu plano',
                        ],
                        'send_email' => true,
                    ]);

                    return true;
                }, 3);

                if ($sent) {
                    $eligible++;
                    $dispatched++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Falha ao disparar recuperação de renovação de assinatura.', [
                    'subscription_id' => $subscription->id,
                    'app_id' => $subscription->app_id,
                    'user_id' => $subscription->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return compact('eligible', 'dispatched', 'skipped', 'failed');
    }

    private function isRecoverable(object $subscription, mixed $oldestRecoverable): bool
    {
        if ((string) $subscription->status !== 'active'
            || $subscription->cancelled_at
            || (int) $subscription->price_cents <= 0
            || ! $subscription->current_period_end) {
            return false;
        }

        $periodEnd = CarbonImmutable::parse((string) $subscription->current_period_end);

        return $periodEnd->lte(now()) && $periodEnd->gte($oldestRecoverable);
    }
}
