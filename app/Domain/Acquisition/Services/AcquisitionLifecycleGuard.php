<?php

namespace App\Domain\Acquisition\Services;

use App\Models\AcquisitionReferral;
use App\Models\User;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Support\Facades\DB;

final class AcquisitionLifecycleGuard
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function assertOnboardingAllowed(string $email): void
    {
        $user = User::query()
            ->where('email', strtolower(trim($email)))
            ->first();

        if (! $user) {
            return;
        }

        $membership = $this->membership($user->id);
        if (! $membership) {
            return;
        }

        $this->assertAllowedStatus((string) $membership->status);
    }

    public function assertResendAllowed(?User $agent, int $referralId): void
    {
        abort_unless($agent, 401, 'Autenticação necessária.');

        $referral = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $agent->id)
            ->findOrFail($referralId);

        abort_unless($referral->referred_user_id, 409, 'Este convite não possui um usuário válido vinculado.');

        $membership = $this->membership((int) $referral->referred_user_id);
        abort_unless($membership, 409, 'O acesso deste produtor à aplicação foi removido. Crie um novo vínculo antes de reenviar o convite.');
        $this->assertAllowedStatus((string) $membership->status);
    }

    public function activate(string $token, Closure $callback): mixed
    {
        $outcome = DB::transaction(function () use ($token, $callback): array {
            $referral = AcquisitionReferral::query()
                ->where('application_id', $this->context->id())
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            if ($referral->status === 'pending' && now()->greaterThanOrEqualTo($referral->expires_at)) {
                $referral->forceFill(['status' => 'expired'])->save();

                return ['expired' => true, 'value' => null];
            }

            abort_unless($referral->status === 'pending', 410, 'Este convite não está mais disponível.');
            abort_unless($referral->referred_user_id, 409, 'Este convite não possui um usuário válido vinculado.');

            $membership = DB::table('application_user')
                ->where('application_id', $this->context->id())
                ->where('user_id', $referral->referred_user_id)
                ->lockForUpdate()
                ->first();

            abort_unless($membership, 409, 'O acesso deste produtor à aplicação foi removido. Solicite um novo vínculo antes de ativar o convite.');
            $this->assertAllowedStatus((string) $membership->status);

            return ['expired' => false, 'value' => $callback()];
        }, 3);

        if ($outcome['expired']) {
            abort(410, 'Este convite expirou. Solicite um novo convite ao agente.');
        }

        return $outcome['value'];
    }

    private function membership(int $userId): ?object
    {
        return DB::table('application_user')
            ->where('application_id', $this->context->id())
            ->where('user_id', $userId)
            ->first();
    }

    private function assertAllowedStatus(string $status): void
    {
        abort_unless(
            in_array($status, ['active', 'pending'], true),
            409,
            'O acesso deste produtor está bloqueado e não pode ser reativado pelo fluxo de aquisição.'
        );
    }
}
