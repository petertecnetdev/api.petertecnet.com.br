<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Models\AppNotification;
use App\Models\Application;
use App\Services\AppNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomatedSubscriptionIntentRecoveryService
{
    public function __construct(private readonly AppNotificationService $notifications)
    {
    }

    /**
     * Send one zero-cost in-app reminder for abandoned subscription PIX checkouts.
     *
     * The AppNotification tuple acts as the idempotency guard, so the job never
     * creates a second reminder for the same intent. E-mail is deliberately
     * disabled here to keep recovery free and avoid unsolicited external contact.
     *
     * @return array{eligible:int,dispatched:int,skipped:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        $delayMinutes = 15;
        $limit = min(max($limit ?? 100, 1), 500);

        $intents = SubscriptionIntent::query()
            ->whereIn('status', ['created', 'payment_pending'])
            ->whereNotNull('user_id')
            ->whereNull('paid_at')
            ->whereNull('activated_at')
            ->whereNull('abandoned_at')
            ->where(function ($query) use ($delayMinutes) {
                $query->where('payment_pending_at', '<=', now()->subMinutes($delayMinutes))
                    ->orWhere(function ($fallback) use ($delayMinutes) {
                        $fallback->whereNull('payment_pending_at')
                            ->where('created_at', '<=', now()->subMinutes($delayMinutes));
                    });
            })
            ->orderBy('id')
            ->limit($limit * 3)
            ->get();

        $eligible = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($intents as $intent) {
            if ($dispatched >= $limit) {
                break;
            }

            $application = $this->resolveApplication((string) $intent->application);
            if (! $application || ! $application->is_active || ! $application->isOperational()) {
                $skipped++;
                continue;
            }

            $referenceId = (string) ($intent->public_id ?: $intent->id);
            $alreadySent = AppNotification::query()
                ->where('app_id', $application->id)
                ->where('user_id', $intent->user_id)
                ->where('type', 'subscription_checkout_recovery')
                ->where('reference_type', 'subscription_intent')
                ->where('reference_id', $referenceId)
                ->exists();

            if ($alreadySent) {
                $skipped++;
                continue;
            }

            $eligible++;

            try {
                $referenceUrl = rtrim((string) $application->url, '/').'/';

                $this->notifications->sendToUser((int) $application->id, (int) $intent->user_id, [
                    'type' => 'subscription_checkout_recovery',
                    'title' => 'Seu plano ainda está aguardando o PIX',
                    'message' => 'Você pode retomar o pagamento sem começar tudo de novo. Toque para continuar de onde parou.',
                    'reference_type' => 'subscription_intent',
                    'reference_id' => $referenceId,
                    'reference_url' => $referenceUrl,
                    'data' => [
                        'subscription_intent_public_id' => $intent->public_id,
                        'plan_code' => $intent->plan_code,
                        'plan_name' => $intent->plan_name,
                        'recovery_channel' => 'in_app',
                        'recovery_action' => 'resume_subscription_pix',
                        'recovery_cta_label' => 'Continuar pagamento',
                    ],
                    'send_email' => false,
                ]);

                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Falha ao disparar recuperação de assinatura pendente.', [
                    'subscription_intent_id' => $intent->id,
                    'application' => $intent->application,
                    'user_id' => $intent->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return compact('eligible', 'dispatched', 'skipped', 'failed');
    }

    private function resolveApplication(string $identity): ?Application
    {
        $identity = trim($identity);
        if ($identity === '') {
            return null;
        }

        return Application::query()
            ->where('slug', $identity)
            ->orWhere('name', $identity)
            ->orWhere('url', 'like', '%://'.$identity.'.%')
            ->first();
    }
}
