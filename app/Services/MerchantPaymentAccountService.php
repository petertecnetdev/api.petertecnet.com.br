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
        private readonly AsaasPaymentService $asaas,
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
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->find($organizationId);

        if (! $organization) {
            return $this->readinessPayload(
                available: false,
                merchantConnected: false,
                settlementMode: 'unavailable',
                publicKey: '',
                methods: [],
                message: 'A organização não é válida neste contexto.',
                provider: null,
            );
        }

        $recipientReady = $this->hasVerifiedPayoutRecipient(
            (int) $organization->id,
            (int) $organization->user_id
        );

        $primary = mb_strtolower(trim((string) config('services.finance.payment_primary_provider', 'asaas')));
        $fallback = mb_strtolower(trim((string) config('services.finance.payment_fallback_provider', 'mercadopago')));
        $platformCollectionEnabled = $this->platformCollectionEnabled();

        if ($primary === 'asaas' && $platformCollectionEnabled && $this->asaas->isConfigured()) {
            return $this->readinessPayload(
                available: true,
                merchantConnected: false,
                settlementMode: 'platform_collection',
                publicKey: '',
                methods: ['pix', 'card', 'boleto'],
                message: $recipientReady
                    ? 'Pagamentos via Asaas habilitados. PIX, cartão e boleto disponíveis.'
                    : 'Pagamentos via Asaas habilitados. O crédito do produtor ficará no ledger até a conclusão do cadastro de recebimento.',
                payoutReady: $recipientReady,
                provider: 'asaas',
                fallbackProvider: $fallback === 'mercadopago' && $this->mercadoPagoPlatformConfigured()
                    ? 'mercadopago'
                    : null,
                requiresPayerDocument: true,
                cardMode: 'redirect',
            );
        }

        $mercadoPago = $this->mercadoPagoReadiness($organizationId, $recipientReady);
        if ($mercadoPago['available']) {
            return $mercadoPago;
        }

        // Se o Asaas estiver configurado mas temporariamente retirado da posição
        // primária, ainda pode ser utilizado como contingência operacional.
        if ($fallback === 'asaas' && $platformCollectionEnabled && $this->asaas->isConfigured()) {
            return $this->readinessPayload(
                available: true,
                merchantConnected: false,
                settlementMode: 'platform_collection',
                publicKey: '',
                methods: ['pix', 'card', 'boleto'],
                message: 'Pagamentos via Asaas disponíveis como contingência.',
                payoutReady: $recipientReady,
                provider: 'asaas',
                fallbackProvider: null,
                requiresPayerDocument: true,
                cardMode: 'redirect',
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
            provider: null,
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
        if (! $account || ! $account->access_token) {
            throw new RuntimeException('Conta de pagamento da organização indisponível.');
        }

        return $this->freshAccessToken($account);
    }

    public function freshAccessToken(object $account): array
    {
        $accessToken = Crypt::decryptString($account->access_token);
        if (! $account->token_expires_at || now()->lt($account->token_expires_at)) {
            return [$account, $accessToken];
        }

        if (! $account->refresh_token) {
            throw new RuntimeException('A autorização do provedor expirou e precisa ser renovada.');
        }

        $tokens = $this->mercadoPago->refreshAccessToken(Crypt::decryptString($account->refresh_token));
        $newAccess = trim((string) ($tokens['access_token'] ?? ''));
        if ($newAccess === '') {
            throw new RuntimeException('O provedor não retornou um novo token de acesso.');
        }

        $metadata = $account->metadata ? json_decode($account->metadata, true) : [];
        if (! empty($tokens['public_key'])) {
            $metadata['public_key'] = $tokens['public_key'];
        }

        DB::table('merchant_payment_accounts')
            ->where('app_id', $this->context->id())
            ->where('id', $account->id)
            ->update([
                'access_token' => Crypt::encryptString($newAccess),
                'refresh_token' => ! empty($tokens['refresh_token'])
                    ? Crypt::encryptString((string) $tokens['refresh_token'])
                    : $account->refresh_token,
                'token_expires_at' => ! empty($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'status' => 'connected',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        return [
            DB::table('merchant_payment_accounts')
                ->where('app_id', $this->context->id())
                ->find($account->id),
            $newAccess,
        ];
    }

    private function mercadoPagoReadiness(int $organizationId, bool $recipientReady): array
    {
        $platformToken = trim((string) config('services.mercadopago.access_token'));
        $platformPublicKey = trim((string) config('services.mercadopago.public_key'));
        $platformConfigured = $this->platformCollectionEnabled() && $platformToken !== '';
        $connectedAccount = $this->account($organizationId, 'mercadopago', true);

        // Prefer native Mercado Pago split whenever the producer connected an account.
        // Platform collection is reserved for producers that chose manual Pix payout.
        if ($connectedAccount && $connectedAccount->access_token) {
            $metadata = $connectedAccount->metadata ? json_decode($connectedAccount->metadata, true) : [];
            $merchantPublicKey = trim((string) ($metadata['public_key'] ?? ''));
            $methods = ['pix', 'boleto'];
            if ($merchantPublicKey !== '') {
                $methods[] = 'card';
            }

            return $this->readinessPayload(
                available: true,
                merchantConnected: true,
                settlementMode: 'automatic_split',
                publicKey: $merchantPublicKey,
                methods: $methods,
                message: $merchantPublicKey !== ''
                    ? 'Mercado Pago conectado. Os recebimentos usam split automático.'
                    : 'PIX habilitado. Reconecte o Mercado Pago para atualizar a chave necessária ao cartão.',
                payoutReady: true,
                provider: 'mercadopago',
                fallbackProvider: null,
                requiresPayerDocument: false,
                cardMode: 'embedded',
            );
        }

        if ($platformConfigured) {
            $this->account($organizationId, 'mercadopago', true);

            $methods = ['pix', 'boleto'];
            if ($platformPublicKey !== '') {
                $methods[] = 'card';
            }

            return $this->readinessPayload(
                available: true,
                merchantConnected: false,
                settlementMode: 'platform_collection',
                publicKey: $platformPublicKey,
                methods: $methods,
                message: $recipientReady
                    ? 'Mercado Pago habilitado. O produtor receberá por repasse Pix manual.'
                    : 'Mercado Pago habilitado. Os valores do produtor ficarão acumulados até a conclusão do cadastro de recebimento.',
                payoutReady: $recipientReady,
                provider: 'mercadopago',
                fallbackProvider: null,
                requiresPayerDocument: false,
                cardMode: 'embedded',
            );
        }

        $account = $this->account($organizationId, 'mercadopago', true);
        $metadata = $account?->metadata ? json_decode($account->metadata, true) : [];
        $merchantConnected = (bool) ($account && $account->access_token);
        $merchantPublicKey = trim((string) ($metadata['public_key'] ?? ''));

        if ($merchantConnected) {
            $methods = ['pix'];
            if ($merchantPublicKey !== '') {
                $methods[] = 'card';
            }

            return $this->readinessPayload(
                available: true,
                merchantConnected: true,
                settlementMode: 'automatic_split',
                publicKey: $merchantPublicKey,
                methods: $methods,
                message: $merchantPublicKey !== ''
                    ? 'Pagamentos habilitados pelo gateway de contingência.'
                    : 'PIX habilitado. Reconecte o provedor para atualizar a chave necessária ao cartão.',
                payoutReady: true,
                provider: 'mercadopago',
                fallbackProvider: null,
                requiresPayerDocument: false,
                cardMode: 'embedded',
            );
        }

        return $this->readinessPayload(
            available: false,
            merchantConnected: false,
            settlementMode: 'sales_disabled',
            publicKey: '',
            methods: [],
            message: 'Mercado Pago indisponível.',
            payoutReady: $recipientReady,
            provider: null,
        );
    }

    /**
     * Contrato estável de disponibilidade de pagamentos para qualquer aplicação.
     */
    private function readinessPayload(
        bool $available,
        bool $merchantConnected,
        string $settlementMode,
        string $publicKey,
        array $methods,
        string $message,
        bool $payoutReady = false,
        ?string $provider = null,
        ?string $fallbackProvider = null,
        bool $requiresPayerDocument = false,
        string $cardMode = 'embedded',
    ): array {
        return [
            'available' => $available,
            'merchant_connected' => $merchantConnected,
            'producer_connected' => $merchantConnected,
            'settlement_mode' => $settlementMode,
            'provider' => $provider,
            'fallback_provider' => $fallbackProvider,
            'public_key' => $publicKey,
            'methods' => array_values(array_unique($methods)),
            'card_mode' => $cardMode,
            'requires_payer_document' => $requiresPayerDocument,
            'message' => $message,
            'payout_ready' => $payoutReady,
            'payout_setup_required' => $available && ! $payoutReady && $settlementMode === 'platform_collection',
        ];
    }

    private function platformCollectionEnabled(): bool
    {
        return (bool) config('services.finance.allow_platform_collection', false)
            || (bool) $this->context->option('commerce.allow_platform_collection', false);
    }

    private function mercadoPagoPlatformConfigured(): bool
    {
        return $this->platformCollectionEnabled()
            && trim((string) config('services.mercadopago.access_token')) !== '';
    }

    private function platformCollectionReady(int $organizationId): bool
    {
        if (! $this->mercadoPagoPlatformConfigured()) {
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
