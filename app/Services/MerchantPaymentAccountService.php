<?php

namespace App\Services;

use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MerchantPaymentAccountService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function account(int $organizationId, string $provider = 'mercadopago', bool $connectedOnly = false): ?object
    {
        $query = DB::table('merchant_payment_accounts')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('provider', $provider);

        if (! $connectedOnly) {
            return $query->first();
        }

        $account = (clone $query)->where('status', 'connected')->first();
        if ($account && $provider === 'mercadopago' && $this->platformCollectionReady($organizationId)) {
            (clone $query)->where('id', $account->id)->update([
                'status' => 'legacy_disabled',
                'updated_at' => now(),
            ]);
            return null;
        }

        return $account;
    }

    public function readiness(int $organizationId): array
    {
        $organization = Production::query()->where('app_id', $this->context->id())->find($organizationId);
        if (! $organization) {
            return $this->readinessPayload(
                available: false,
                merchantConnected: false,
                settlementMode: 'unavailable',
                publicKey: '',
                methods: [],
                message: 'A organização não é válida neste contexto.',
            );
        }

        $platformToken = trim((string) config('services.mercadopago.access_token'));
        $platformPublicKey = trim((string) config('services.mercadopago.public_key'));
        $platformConfigured = (bool) $this->context->option('commerce.allow_platform_collection', false) && $platformToken !== '';
        $recipientReady = $this->hasVerifiedPayoutRecipient((int) $organization->id, (int) $organization->user_id);

        // A capacidade de vender é independente da configuração de repasse.
        // Enquanto a plataforma estiver habilitada, o checkout coleta normalmente
        // pela conta da Peter Tecnet. Se o produtor ainda não concluiu KYC/Pix,
        // o crédito permanece no ledger até que um destino de repasse seja ativado.
        if ($platformConfigured) {
            $this->account($organizationId, 'mercadopago', true);
            $methods = ['pix'];
            if ($platformPublicKey !== '') $methods[] = 'card';

            return $this->readinessPayload(
                available: true,
                merchantConnected: false,
                settlementMode: 'platform_collection',
                publicKey: $platformPublicKey,
                methods: $methods,
                message: $recipientReady
                    ? 'Pagamentos habilitados com recebimento e repasse pela plataforma.'
                    : 'Pagamentos habilitados. Os valores do produtor ficarão acumulados na plataforma até a conclusão do cadastro de recebimento.',
                payoutReady: $recipientReady,
            );
        }

        $account = $this->account($organizationId, 'mercadopago', true);
        $metadata = $account?->metadata ? json_decode($account->metadata, true) : [];
        $merchantConnected = (bool) ($account && $account->access_token);
        $merchantPublicKey = trim((string) ($metadata['public_key'] ?? ''));

        // Automatic split remains a zero-downtime fallback for already-active
        // generic merchant accounts while organizations migrate to platform
        // settlement. It is never selected when platform settlement is ready.
        if ($merchantConnected) {
            $methods = ['pix'];
            if ($merchantPublicKey !== '') $methods[] = 'card';

            return $this->readinessPayload(
                available: true,
                merchantConnected: true,
                settlementMode: 'automatic_split',
                publicKey: $merchantPublicKey,
                methods: $methods,
                message: $merchantPublicKey !== ''
                    ? 'Pagamentos habilitados com split automático.'
                    : 'PIX habilitado. Reconecte o provedor para atualizar a chave necessária ao cartão.',
                payoutReady: true,
            );
        }

        return $this->readinessPayload(
            available: false,
            merchantConnected: false,
            settlementMode: 'sales_disabled',
            publicKey: '',
            methods: [],
            message: 'A plataforma de pagamentos ainda não está habilitada para novas vendas.',
            payoutReady: $recipientReady,
        );
    }

    public function disableLegacyMerchantAccount(int $organizationId, string $provider = 'mercadopago'): void
    {
        DB::table('merchant_payment_accounts')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('provider', $provider)
            ->where('status', 'connected')
            ->update([
                'status' => 'legacy_disabled',
                'updated_at' => now(),
            ]);
    }

    public function accessTokenForOrganization(int $organizationId): array
    {
        $account = $this->account($organizationId, 'mercadopago', true);
        if (! $account || ! $account->access_token) throw new RuntimeException('Conta de pagamento da organização indisponível.');
        return $this->freshAccessToken($account);
    }

    public function freshAccessToken(object $account): array
    {
        $accessToken = Crypt::decryptString($account->access_token);
        if (! $account->token_expires_at || now()->lt($account->token_expires_at)) return [$account, $accessToken];
        if (! $account->refresh_token) throw new RuntimeException('A autorização do provedor expirou e precisa ser renovada.');

        $tokens = $this->mercadoPago->refreshAccessToken(Crypt::decryptString($account->refresh_token));
        $newAccess = trim((string) ($tokens['access_token'] ?? ''));
        if ($newAccess === '') throw new RuntimeException('O provedor não retornou um novo token de acesso.');
        $metadata = $account->metadata ? json_decode($account->metadata, true) : [];
        if (! empty($tokens['public_key'])) $metadata['public_key'] = $tokens['public_key'];

        DB::table('merchant_payment_accounts')->where('app_id', $this->context->id())->where('id', $account->id)->update([
            'access_token' => Crypt::encryptString($newAccess),
            'refresh_token' => ! empty($tokens['refresh_token']) ? Crypt::encryptString((string) $tokens['refresh_token']) : $account->refresh_token,
            'token_expires_at' => ! empty($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'status' => 'connected',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return [
            DB::table('merchant_payment_accounts')->where('app_id', $this->context->id())->find($account->id),
            $newAccess,
        ];
    }

    /**
     * Build one stable payment-readiness contract for every application.
     * `merchant_connected` is canonical. `producer_connected` remains a
     * compatibility alias for older event clients and contains no policy of
     * its own, so payment readiness continues to have a single source of truth.
     */
    private function readinessPayload(
        bool $available,
        bool $merchantConnected,
        string $settlementMode,
        string $publicKey,
        array $methods,
        string $message,
        bool $payoutReady = false,
    ): array {
        return [
            'available' => $available,
            'merchant_connected' => $merchantConnected,
            'producer_connected' => $merchantConnected,
            'settlement_mode' => $settlementMode,
            'public_key' => $publicKey,
            'methods' => $methods,
            'message' => $message,
            'payout_ready' => $payoutReady,
            'payout_setup_required' => $available && ! $payoutReady && $settlementMode === 'platform_collection',
        ];
    }

    private function platformCollectionReady(int $organizationId): bool
    {
        if (! (bool) $this->context->option('commerce.allow_platform_collection', false)) {
            return false;
        }

        if (trim((string) config('services.mercadopago.access_token')) === '') {
            return false;
        }

        return Production::query()
            ->where('app_id', $this->context->id())
            ->whereKey($organizationId)
            ->exists();
    }

    private function hasVerifiedPayoutRecipient(int $organizationId, int $ownerUserId): bool
    {
        return DB::table('financial_payout_destinations as destination')
            ->join('financial_beneficiaries as beneficiary', 'beneficiary.id', '=', 'destination.beneficiary_id')
            ->where('destination.source_type', 'production')
            ->where('destination.source_id', $organizationId)
            ->whereIn('destination.status', ['active', 'cooling'])
            ->whereNotNull('destination.verified_at')
            ->where('beneficiary.user_id', $ownerUserId)
            ->where('beneficiary.status', 'verified')
            ->exists();
    }
}
