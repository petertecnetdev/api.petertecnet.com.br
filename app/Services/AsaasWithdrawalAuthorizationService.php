<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsaasWithdrawalAuthorizationService
{
    public function authorize(array $payload): array
    {
        if (strtoupper(trim((string) ($payload['type'] ?? ''))) !== 'TRANSFER') {
            return $this->refuse('Operação não reconhecida pelo módulo de repasses Peter Tecnet.');
        }

        $transfer = is_array($payload['transfer'] ?? null) ? $payload['transfer'] : [];
        $transferId = trim((string) ($transfer['id'] ?? ''));
        $value = round((float) ($transfer['value'] ?? 0), 2);
        $operationType = strtoupper(trim((string) ($transfer['operationType'] ?? '')));
        $externalReference = trim((string) ($transfer['externalReference'] ?? ''));

        if ($transferId === '' || $value <= 0) {
            return $this->refuse('Transferência sem identificador ou valor válido.');
        }

        if ($operationType !== '' && $operationType !== 'PIX') {
            return $this->refuse('Somente transferências Pix são autorizadas por este fluxo.');
        }

        $payout = DB::table('financial_payouts')
            ->where('provider', 'asaas')
            ->where('provider_transfer_id', $transferId)
            ->first();

        if (!$payout) {
            Log::warning('Asaas solicitou autorização para saque desconhecido.', [
                'provider_transfer_id' => $transferId,
                'value' => $value,
            ]);
            return $this->refuse('Transferência não encontrada no ledger Peter Tecnet.');
        }

        if (!in_array((string) $payout->status, ['pending', 'processing', 'provider_unknown'], true)) {
            return $this->refuse('Transferência não está em estado autorizável.');
        }

        if ((string) $payout->risk_status !== 'approved') {
            return $this->refuse('Transferência bloqueada pelas regras de risco.');
        }

        if (abs((float) $payout->amount - $value) > 0.009) {
            Log::warning('Valor divergente em autorização de saque Asaas.', [
                'payout_id' => $payout->id,
                'expected' => (float) $payout->amount,
                'received' => $value,
            ]);
            return $this->refuse('Valor da transferência diverge do repasse autorizado.');
        }

        if ($externalReference !== '' && !hash_equals((string) $payout->reference, $externalReference)) {
            return $this->refuse('Referência externa não corresponde ao repasse autorizado.');
        }

        $metadata = $payout->metadata ? json_decode($payout->metadata, true) : [];
        if (!is_array($metadata)) $metadata = [];
        $metadata['withdrawal_authorized_at'] = now()->toIso8601String();
        $metadata['withdrawal_authorization_transfer_id'] = $transferId;

        DB::table('financial_payouts')->where('id', $payout->id)->update([
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return ['status' => 'APPROVED'];
    }

    private function refuse(string $reason): array
    {
        return [
            'status' => 'REFUSED',
            'refuseReason' => $reason,
        ];
    }
}
