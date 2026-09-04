<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Finance\Services\PaymentOrchestratorService;
use App\Domain\Finance\Services\PaymentReceivingProfileService;
use Illuminate\Support\Facades\DB;

final class LeasePaymentService
{
    public function __construct(
        private readonly PaymentReceivingProfileService $profiles,
        private readonly PaymentOrchestratorService $payments,
    ) {}

    public function receivingProfile(int $appId, int $userId): array
    {
        $profile = $this->profiles->active($appId, $userId, 'pix');

        return [
            'can_receive' => $this->canReceive($appId, $userId),
            'profile' => $this->profiles->publicProfile($profile),
        ];
    }

    public function saveReceivingProfile(int $appId, int $userId, array $data): array
    {
        abort_unless($this->canReceive($appId, $userId), 403, 'Cadastre um imóvel antes de configurar o recebimento PIX.');
        $profile = $this->profiles->savePix($appId, $userId, $data);

        return [
            'message' => 'Chave PIX salva para recebimentos.',
            'profile' => $this->profiles->publicProfile($profile),
        ];
    }

    public function prepare(int $appId, string $appSlug, object $user, int $leaseId, int $chargeId, string $method): array
    {
        $lease = DB::table('leases')
            ->where('app_id', $appId)
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($lease, 404, 'Locação não encontrada.');

        $isTenant = (int) ($lease->tenant_user_id ?? 0) === (int) $user->id
            || (! $lease->tenant_user_id && $lease->tenant_email && strcasecmp((string) $lease->tenant_email, (string) $user->email) === 0);
        abort_unless($isTenant, 403, 'Somente o inquilino vinculado pode preparar o pagamento desta cobrança.');

        if (! $lease->tenant_user_id) {
            DB::table('leases')->where('app_id', $appId)->where('id', $lease->id)->update([
                'tenant_user_id' => $user->id,
                'updated_at' => now(),
            ]);
        }

        $charge = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->where('lease_id', $leaseId)
            ->where('id', $chargeId)
            ->first();
        abort_unless($charge, 404, 'Cobrança não encontrada.');
        abort_if($charge->status === 'paid', 422, 'Esta cobrança já foi paga.');

        $base = [
            'app_id' => $appId,
            'app_slug' => $appSlug,
            'source_type' => 'lease_charge',
            'source_reference' => $charge->public_id,
            'source_id' => $charge->id,
            'user_id' => $lease->tenant_user_id ?: $user->id,
            'method' => $method,
            'amount' => (float) $charge->amount,
            'metadata' => ['lease_id' => $leaseId, 'charge_id' => $chargeId],
        ];

        if ($method === 'pix') {
            $prepared = $this->payments->prepareDirectPix(array_merge($base, [
                'recipient_user_id' => (int) $lease->landlord_user_id,
                'description' => (string) $charge->description,
                'txid' => $charge->pix_txid ?: null,
                'confirmation_mode' => 'landlord_manual',
            ]));

            DB::table('lease_charges')->where('app_id', $appId)->where('id', $charge->id)->update([
                'ecosystem_payment_id' => $prepared['payment']->id,
                'payment_receiving_profile_id' => $prepared['profile']->id,
                'payment_method' => 'pix',
                'provider' => 'pix_direct',
                'provider_payment_id' => $prepared['txid'],
                'pix_txid' => $prepared['txid'],
                'pix_payload' => $prepared['payload'],
                'payment_recipient_snapshot' => $this->json($prepared['recipient_snapshot']),
                'status' => 'processing',
                'updated_at' => now(),
            ]);

            $freshCharge = DB::table('lease_charges')->where('app_id', $appId)->where('id', $chargeId)->firstOrFail();

            return [
                'payment' => $prepared['payment'],
                'charge' => $freshCharge,
                'pix_copy_paste' => $prepared['payload'],
                'copy_paste' => $prepared['payload'],
                'qr_code_text' => $prepared['payload'],
                'pix' => [
                    'copy_paste' => $prepared['payload'],
                    'txid' => $prepared['txid'],
                    'key_type' => $prepared['profile']->pix_key_type,
                    'key_masked' => $prepared['profile']->pix_key_masked,
                    'holder_name' => $prepared['profile']->holder_name,
                    'merchant_city' => $prepared['profile']->merchant_city,
                ],
                'provider_checkout_required' => false,
                'confirmation_mode' => 'landlord_manual',
                'message' => 'PIX gerado para pagamento direto ao proprietário.',
            ];
        }

        $payment = $this->payments->prepareProvider(array_merge($base, [
            'provider' => 'mercadopago',
        ]));

        DB::table('lease_charges')->where('app_id', $appId)->where('id', $chargeId)->update([
            'ecosystem_payment_id' => $payment->id,
            'payment_method' => $method,
            'provider' => 'mercadopago',
            'status' => 'processing',
            'updated_at' => now(),
        ]);

        return [
            'payment' => $payment,
            'charge' => DB::table('lease_charges')->where('app_id', $appId)->where('id', $chargeId)->firstOrFail(),
            'provider_checkout_required' => true,
        ];
    }

    public function markPaid(int $appId, string $appSlug, int $actorUserId, int $leaseId, int $chargeId, ?string $paymentMethod = null): object
    {
        $charge = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->where('lease_id', $leaseId)
            ->where('id', $chargeId)
            ->firstOrFail();

        $method = $paymentMethod ?? $charge->payment_method ?? 'other';
        $payment = $this->payments->markPaid([
            'app_id' => $appId,
            'app_slug' => $appSlug,
            'source_type' => 'lease_charge',
            'source_reference' => $charge->public_id,
            'source_id' => $charge->id,
            'user_id' => $actorUserId,
            'method' => $method,
            'provider' => $charge->provider ?: 'manual',
            'provider_payment_id' => $charge->provider_payment_id,
            'amount' => (float) $charge->amount,
            'metadata' => ['lease_id' => $leaseId, 'charge_id' => $chargeId],
        ]);

        DB::table('lease_charges')->where('app_id', $appId)->where('id', $charge->id)->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $method,
            'ecosystem_payment_id' => $payment->id,
            'updated_at' => now(),
        ]);

        return DB::table('lease_charges')->where('app_id', $appId)->where('id', $chargeId)->firstOrFail();
    }

    private function canReceive(int $appId, int $userId): bool
    {
        return DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->exists()
            || DB::table('leases')
                ->where('app_id', $appId)
                ->where('landlord_user_id', $userId)
                ->whereNull('deleted_at')
                ->exists();
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
