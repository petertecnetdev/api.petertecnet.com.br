<?php

namespace App\Services;

use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class OrganizationSalesReadinessService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
        private readonly MerchantPaymentAccountService $payments,
    ) {}

    public function status(int $organizationId): array
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->find($organizationId);

        if (! $organization) {
            return [
                'ready' => false,
                'agreement' => false,
                'payout' => false,
                'payment' => false,
                'code' => 'organization_not_found',
                'message' => 'A organização não foi encontrada neste contexto.',
            ];
        }

        $agreement = DB::table('contract_acceptances')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('contract_version', $this->agreements->version())
            ->exists();

        $payout = DB::table('financial_payout_destinations as destination')
            ->join('financial_beneficiaries as beneficiary', 'beneficiary.id', '=', 'destination.beneficiary_id')
            ->where('destination.source_type', 'production')
            ->where('destination.source_id', $organizationId)
            ->whereIn('destination.status', ['active', 'cooling'])
            ->whereNotNull('destination.verified_at')
            ->where('beneficiary.user_id', $organization->user_id)
            ->where('beneficiary.status', 'verified')
            ->exists();

        $payment = $this->payments->readiness($organizationId);
        $paymentAvailable = (bool) ($payment['available'] ?? false);
        $ready = $agreement && $payout && $paymentAvailable;

        $code = $ready ? 'ready' : (! $agreement ? 'agreement_required' : (! $payout ? 'payout_required' : 'payment_unavailable'));
        $message = match ($code) {
            'ready' => 'Produção pronta para vender.',
            'agreement_required' => 'O produtor precisa assinar o contrato vigente antes de vender ingressos pagos.',
            'payout_required' => 'O produtor precisa concluir a verificação financeira e cadastrar uma chave Pix válida antes de vender.',
            default => (string) ($payment['message'] ?? 'O meio de pagamento está temporariamente indisponível.'),
        };

        return [
            'ready' => $ready,
            'agreement' => $agreement,
            'payout' => $payout,
            'payment' => $paymentAvailable,
            'code' => $code,
            'message' => $message,
            'methods' => array_values($payment['methods'] ?? []),
        ];
    }
}
