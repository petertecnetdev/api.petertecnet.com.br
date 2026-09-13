<?php

namespace App\Domain\Finance\Services;

use App\Models\AppNotification;
use App\Models\Application;
use App\Services\AppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomatedSubscriptionRenewalReminderService
{
    private const INITIAL_REMINDER_LEAD_HOURS = 72;

    private const FINAL_REMINDER_LEAD_HOURS = 24;

    public function __construct(private readonly AppNotificationService $notifications)
    {
    }

    /**
     * Remind paying subscribers before their current period ends.
     *
     * A subscriber can receive one reminder around 72h and one final reminder inside
     * the last 24h. Each stage is idempotent per billing period. The subscription is
     * revalidated under a row lock immediately before delivery so cancellation or a
     * concurrent renewal always wins.
     *
     * @return array{eligible:int,dispatched:int,skipped:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        $limit = min(max($limit ?? 100, 1), 500);
        $now = now();
        $remindBefore = $now->copy()->addHours(self::INITIAL_REMINDER_LEAD_HOURS);

        $subscriptions = DB::table('ecosystem_subscriptions')
            ->where('status', 'active')
            ->whereNull('cancelled_at')
            ->where('price_cents', '>', 0)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', $now)
            ->where('current_period_end', '<=', $remindBefore)
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
                $sent = DB::transaction(function () use ($subscription, $application, $now, $remindBefore): bool {
                    $fresh = DB::table('ecosystem_subscriptions')
                        ->where('id', (int) $subscription->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $fresh || ! $this->isReminderEligible($fresh, $now, $remindBefore)) {
                        return false;
                    }

                    $periodEnd = CarbonImmutable::parse((string) $fresh->current_period_end);
                    $stage = $this->reminderStage($periodEnd, $now);
                    $leadHours = $stage === 'final'
                        ? self::FINAL_REMINDER_LEAD_HOURS
                        : self::INITIAL_REMINDER_LEAD_HOURS;
                    $referenceId = hash('sha256', implode('|', [
                        (string) $fresh->public_id,
                        $periodEnd->utc()->format('Y-m-d H:i:s'),
                        $stage,
                    ]));

                    $alreadySent = AppNotification::query()
                        ->where('app_id', (int) $application->id)
                        ->where('user_id', (int) $fresh->user_id)
                        ->where('type', 'subscription_renewal_reminder')
                        ->where('reference_type', 'subscription_renewal_period')
                        ->where('reference_id', $referenceId)
                        ->exists();

                    if ($alreadySent) {
                        return false;
                    }

                    $query = http_build_query([
                        'source' => 'payment_recovery',
                        'resume' => '1',
                        'plan' => (string) $fresh->plan_code,
                    ]);
                    $referenceUrl = rtrim((string) $application->url, '/').'/planos?'.$query;

                    $this->notifications->sendToUser((int) $application->id, (int) $fresh->user_id, [
                        'type' => 'subscription_renewal_reminder',
                        'title' => $stage === 'final'
                            ? 'Seu plano vence nas próximas 24 horas'
                            : 'Seu plano vence em breve',
                        'message' => $stage === 'final'
                            ? 'Renove agora para evitar interrupção no acesso. Seus dias já pagos serão preservados.'
                            : 'Renove agora sem perder nenhum dia já pago e mantenha seu acesso ativo sem interrupção.',
                        'reference_type' => 'subscription_renewal_period',
                        'reference_id' => $referenceId,
                        'reference_url' => $referenceUrl,
                        'data' => [
                            'subscription_public_id' => $fresh->public_id,
                            'plan_code' => $fresh->plan_code,
                            'period_ends_at' => $periodEnd->toIso8601String(),
                            'reminder_stage' => $stage,
                            'reminder_lead_hours' => $leadHours,
                            'recovery_channel' => 'in_app_email',
                            'recovery_action' => 'renew_subscription_early',
                            'recovery_cta_label' => 'Renovar agora',
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
                Log::warning('Falha ao disparar lembrete antecipado de renovação.', [
                    'subscription_id' => $subscription->id,
                    'app_id' => $subscription->app_id,
                    'user_id' => $subscription->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return compact('eligible', 'dispatched', 'skipped', 'failed');
    }

    private function isReminderEligible(object $subscription, mixed $now, mixed $remindBefore): bool
    {
        if ((string) $subscription->status !== 'active'
            || $subscription->cancelled_at
            || (int) $subscription->price_cents <= 0
            || ! $subscription->current_period_end) {
            return false;
        }

        $periodEnd = CarbonImmutable::parse((string) $subscription->current_period_end);

        return $periodEnd->gt($now) && $periodEnd->lte($remindBefore);
    }

    private function reminderStage(CarbonImmutable $periodEnd, mixed $now): string
    {
        return $periodEnd->lte(CarbonImmutable::instance($now)->addHours(self::FINAL_REMINDER_LEAD_HOURS))
            ? 'final'
            : 'initial';
    }
}
