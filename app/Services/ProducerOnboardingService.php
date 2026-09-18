<?php

namespace App\Services;

use App\Mail\ProducerSalesReadyMail;
use App\Models\AcquisitionReferral;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class ProducerOnboardingService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
        private readonly MerchantPaymentAccountService $paymentAccounts,
    ) {}

    public function statusForUser(int $organizationId, ?User $user, bool $isAdministrator = false): array
    {
        abort_unless($user, 401);

        $production = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($organizationId);

        abort_unless(
            $isAdministrator || (int) $production->user_id === (int) $user->id,
            403
        );

        return $this->status($production);
    }

    public function status(Production $production): array
    {
        abort_unless((int) $production->app_id === $this->context->id(), 404, 'Organização não encontrada neste contexto.');

        $referral = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('production_id', $production->id)
            ->latest()
            ->first();
        $assistedOnboarding = data_get((array) ($referral?->metadata ?? []), 'onboarding_mode') === 'assisted';

        $owner = User::query()->find($production->user_id);
        $membership = $owner
            ? DB::table('application_user')
                ->where('application_id', $this->context->id())
                ->where('user_id', $owner->id)
                ->first()
            : null;

        $accountActive = (bool) ($owner?->email_verified_at && $membership?->status === 'active');
        $productionReady = $accountActive && (bool) $production->is_published;
        $eventsCount = $production->events()->count();
        $firstEvent = $production->events()
            ->orderBy('created_at')
            ->first(['id', 'production_id', 'title', 'slug', 'start_date', 'end_date', 'is_published']);

        $agreementRequired = $assistedOnboarding
            && (bool) $this->context->option('events.requires_producer_agreement', false);
        $agreementSigned = ! $agreementRequired || DB::table('contract_acceptances')
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('contract_version', $this->agreements->version())
            ->exists();

        $payment = $this->paymentAccounts->readiness((int) $production->id);
        $paymentCollectionReady = (bool) ($payment['available'] ?? false);
        $payoutRequired = $assistedOnboarding
            && (bool) $this->context->option('commerce.require_payout_setup_before_sales', false);
        $payoutReady = ! $payoutRequired || (bool) ($payment['payout_ready'] ?? false);

        $assistedSetupReady = ! $assistedOnboarding || ($accountActive && $productionReady && $agreementSigned && $payoutReady);
        $canSell = $assistedSetupReady && $paymentCollectionReady;

        $steps = [
            $this->step('account', 'Acesso do produtor', $accountActive, 'Confirme o e-mail e ative o acesso à aplicação.'),
            $this->step('production', 'Produção cadastrada', $productionReady, 'Ative o acesso para liberar a produção criada pelo agente.'),
            $this->step('event', 'Primeiro evento preparado', $eventsCount > 0, $eventsCount > 0 ? 'O primeiro evento já está pronto para revisão.' : 'Crie seu primeiro evento.'),
            $this->step('agreement', 'Contrato da plataforma', $agreementSigned, $agreementRequired ? 'Leia e assine eletronicamente o termo vigente.' : 'Nenhuma assinatura adicional é exigida.'),
            $this->step('payout', 'Recebimentos configurados', $payoutReady, $payoutRequired ? 'Conclua a verificação financeira e cadastre uma chave Pix válida.' : 'O cadastro de repasse pode ser concluído depois.'),
            $this->step('payments', 'Cobranças disponíveis', $paymentCollectionReady, $paymentCollectionReady ? 'O checkout está operacional.' : 'A infraestrutura de pagamentos ainda não está disponível.'),
        ];

        $completed = collect($steps)->where('complete', true)->count();
        $next = collect($steps)->firstWhere('complete', false);

        return [
            'organization' => $production->only(['id', 'name', 'slug', 'is_published']),
            'owner' => $owner?->only(['id', 'first_name', 'last_name', 'email']),
            'status' => $assistedOnboarding
                ? $this->statusName($accountActive, $agreementSigned, $payoutReady, $paymentCollectionReady)
                : ($paymentCollectionReady ? 'ready_to_sell' : 'awaiting_payments'),
            'assisted_onboarding' => $assistedOnboarding,
            'can_sell_tickets' => $canSell,
            'completion' => [
                'completed' => $completed,
                'total' => count($steps),
                'percentage' => (int) round(($completed / max(count($steps), 1)) * 100),
            ],
            'steps' => $steps,
            'next_step' => $next,
            'events_count' => $eventsCount,
            'first_event' => $firstEvent,
            'agreement' => [
                'required' => $agreementRequired,
                'signed' => $agreementSigned,
                'version' => $this->agreements->version(),
            ],
            'finance' => [
                'payment_collection_ready' => $paymentCollectionReady,
                'payout_required_before_sales' => $payoutRequired,
                'payout_ready' => (bool) ($payment['payout_ready'] ?? false),
                'payout_setup_required' => $payoutRequired && ! (bool) ($payment['payout_ready'] ?? false),
                'settlement_mode' => $payment['settlement_mode'] ?? null,
                'message' => $payment['message'] ?? null,
            ],
        ];
    }

    public function assertCanSell(Production $production): array
    {
        $status = $this->status($production);

        // Existing/self-service productions keep their established lifecycle.
        // The strict contract + Pix gate is introduced only for assisted onboarding.
        if (! $status['assisted_onboarding']) {
            return $status;
        }

        if ($status['can_sell_tickets']) {
            return $status;
        }

        $nextKey = data_get($status, 'next_step.key');

        match ($nextKey) {
            'account', 'production' => abort(428, 'O produtor precisa ativar o acesso antes de iniciar as vendas.'),
            'agreement' => abort(428, 'Assine o contrato vigente da plataforma antes de iniciar as vendas.'),
            'payout' => abort(428, 'Conclua a verificação financeira e cadastre sua chave Pix antes de iniciar as vendas.'),
            'payments' => abort(503, 'A infraestrutura de pagamentos está temporariamente indisponível para novas vendas.'),
            default => abort(428, 'Conclua o onboarding comercial da produção antes de iniciar as vendas.'),
        };
    }

    public function notifyIfSalesReady(Production $production): bool
    {
        $status = $this->status($production);
        if (! $status['assisted_onboarding'] || ! $status['can_sell_tickets']) {
            return false;
        }

        $referral = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('status', 'accepted')
            ->latest()
            ->first();

        if (! $referral) {
            return false;
        }

        $metadata = (array) ($referral->metadata ?? []);
        if (! empty($metadata['sales_ready_email_sent_at'])) {
            return false;
        }

        $owner = User::query()->find($production->user_id);
        if (! $owner?->email) {
            return false;
        }

        try {
            Mail::to($owner->email)->send(new ProducerSalesReadyMail(
                user: $owner,
                application: $this->context->application(),
                production: $production,
                firstEvent: $status['first_event'],
            ));

            $metadata['sales_ready_email_sent_at'] = now()->toIso8601String();
            $metadata['sales_ready_notified_by'] = 'producer_onboarding_service';
            $referral->forceFill(['metadata' => $metadata])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar confirmação de onboarding comercial concluído.', [
                'application_id' => $this->context->id(),
                'production_id' => $production->id,
                'referral_id' => $referral->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function step(string $key, string $label, bool $complete, string $detail): array
    {
        return compact('key', 'label', 'complete', 'detail');
    }

    private function statusName(bool $accountActive, bool $agreementSigned, bool $payoutReady, bool $paymentCollectionReady): string
    {
        if (! $accountActive) return 'awaiting_activation';
        if (! $agreementSigned) return 'awaiting_agreement';
        if (! $payoutReady) return 'awaiting_payout';
        if (! $paymentCollectionReady) return 'awaiting_payments';
        return 'ready_to_sell';
    }
}
