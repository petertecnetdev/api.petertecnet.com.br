<?php

namespace App\Services;

use App\Domain\Finance\Contracts\PayoutProvider;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class RecipientSettlementService
{
    public function __construct(
        private readonly FinancialIdentityService $identity,
        private readonly PayoutProvider $payoutProvider,
        private readonly ApplicationContext $context,
    ) {}

    public function overview(User $user): array
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        $destination = $this->destination((int) $user->id, true);
        $history = DB::table('financial_payouts')
            ->where('app_slug', $this->context->slug())
            ->where('source_type', 'user')
            ->where('source_id', $user->id)
            ->latest('id')->limit(50)->get()->map(fn ($row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'provider' => $row->provider,
                'status' => $row->status,
                'amount' => (float) $row->amount,
                'risk_status' => $row->risk_status,
                'requested_at' => $row->requested_at,
                'paid_at' => $row->paid_at,
                'failed_at' => $row->failed_at,
            ]);

        return [
            'identity' => $this->identity->overview($user),
            'destination' => $destination ? $this->destinationPayload($destination) : null,
            'balance' => $this->balance($user),
            'payouts' => $history,
            'payout_provider' => $this->payoutProvider->name(),
            'ready_for_sales' => (bool) ($beneficiary && $beneficiary->status === 'verified' && $destination && in_array($destination->status, ['active', 'cooling'], true)),
            'ready_for_payout' => (bool) ($beneficiary && $beneficiary->status === 'verified' && $destination && $destination->status === 'active'),
        ];
    }

    public function assertReadyForCollection(User $user): void
    {
        $overview = $this->overview($user);
        if (! ($overview['ready_for_sales'] ?? false)) {
            throw ValidationException::withMessages([
                'recipient' => 'O responsável pelo recebimento precisa concluir a verificação de identidade e cadastrar uma chave Pix antes de receber pagamentos online.',
            ]);
        }
    }

    public function savePixDestination(User $user, string $type, string $key): array
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        if (! $beneficiary || $beneficiary->status !== 'verified') {
            throw ValidationException::withMessages(['identity' => 'Conclua a verificação de identidade antes de cadastrar a chave Pix.']);
        }

        $type = strtoupper(trim($type));
        if (! in_array($type, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
            throw ValidationException::withMessages(['pix_key_type' => 'Tipo de chave Pix inválido.']);
        }
        $normalized = $this->payoutProvider->normalizePixKey($type, $key);
        if ($normalized === '') throw ValidationException::withMessages(['pix_key' => 'Informe uma chave Pix válida.']);

        $lookup = $this->payoutProvider->lookupPixKey($type, $normalized);
        $ownerName = trim((string) (data_get($lookup, 'owner.name') ?: data_get($lookup, 'name', '')));
        $ownerDocument = trim((string) (data_get($lookup, 'owner.cpfCnpj') ?: data_get($lookup, 'cpfCnpj', '')));
        if (! $this->documentMatches($ownerDocument, $this->identity->decryptedDocument($beneficiary))) {
            throw ValidationException::withMessages(['pix_key' => 'A chave Pix informada não pertence ao documento verificado do titular.']);
        }

        $now = now();
        $existing = DB::table('financial_payout_destinations')->where('source_type', 'user')->where('source_id', $user->id)->first();
        $fingerprint = $this->identity->fingerprint($type.':'.$normalized);
        $sameKey = $existing && hash_equals((string) $existing->pix_key_hash, $fingerprint);
        $coolingHours = max(0, (int) config('services.finance.payout_destination_cooling_hours', 24));
        $replacement = (bool) ($existing && ! $sameKey);
        $status = $replacement && $coolingHours > 0 ? 'cooling' : 'active';
        $coolingUntil = $status === 'cooling' ? $now->copy()->addHours($coolingHours) : null;

        DB::table('financial_payout_destinations')->updateOrInsert(
            ['source_type' => 'user', 'source_id' => $user->id],
            [
                'beneficiary_id' => $beneficiary->id,
                'provider' => $this->payoutProvider->name(),
                'type' => 'pix',
                'pix_key_type' => $type,
                'pix_key' => Crypt::encryptString($normalized),
                'pix_key_hash' => $fingerprint,
                'pix_key_masked' => $this->maskPixKey($type, $normalized),
                'holder_name' => $ownerName !== '' ? $ownerName : $beneficiary->legal_name,
                'holder_document_masked' => $ownerDocument,
                'status' => $status,
                'provider_snapshot' => json_encode(['lookup' => $lookup, 'verified_at' => $now->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'verified_at' => $now,
                'cooling_until' => $coolingUntil,
                'changed_at' => $replacement ? $now : ($existing?->changed_at ?? $now),
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        return $this->overview($user);
    }

    public function creditPayment(object $payment, User $recipient, float $netAmount, array $metadata = []): void
    {
        $amount = round($netAmount, 2);
        if ($amount <= 0) return;
        $holdHours = max(0, (int) config('services.finance.payout_hold_hours', 24));
        DB::table('financial_ledger_entries')->updateOrInsert(
            ['payment_id' => $payment->id, 'source_type' => 'user', 'source_id' => $recipient->id, 'type' => 'recipient_credit'],
            [
                'app_id' => $payment->app_id,
                'app_slug' => $payment->app_slug,
                'status' => 'posted',
                'currency' => $payment->currency ?: 'BRL',
                'amount' => $amount,
                'description' => 'Crédito líquido de pagamento confirmado',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'available_at' => $holdHours > 0 ? now()->addHours($holdHours) : now(),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    public function reversePayment(object $payment, User $recipient, float $amount, string $reason): void
    {
        $value = round(abs($amount), 2);
        if ($value <= 0) return;
        DB::table('financial_ledger_entries')->updateOrInsert(
            ['payment_id' => $payment->id, 'source_type' => 'user', 'source_id' => $recipient->id, 'type' => 'recipient_reversal'],
            [
                'app_id' => $payment->app_id,
                'app_slug' => $payment->app_slug,
                'status' => 'posted',
                'currency' => $payment->currency ?: 'BRL',
                'amount' => -$value,
                'description' => 'Reversão financeira: '.$reason,
                'metadata' => json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    public function balance(User $user): array
    {
        $base = DB::table('financial_ledger_entries')
            ->where('app_slug', $this->context->slug())
            ->where('source_type', 'user')->where('source_id', $user->id)->where('status', 'posted');
        $gross = (float) (clone $base)->sum('amount');
        $eligible = (float) (clone $base)->where(function ($q) {
            $q->whereNull('available_at')->orWhere('available_at', '<=', now());
        })->sum('amount');
        $pendingSales = max(0, $gross - $eligible);
        $reservePercent = max(0, min((float) config('services.finance.payout_reserve_percent', 10), 100));
        $securityReserve = round(max(0, $eligible) * ($reservePercent / 100), 2);
        $payoutBase = DB::table('financial_payouts')->where('app_slug', $this->context->slug())->where('source_type', 'user')->where('source_id', $user->id);
        $inFlight = (float) (clone $payoutBase)->whereIn('status', ['pending', 'processing', 'provider_unknown'])->sum('amount');
        $paid = (float) (clone $payoutBase)->where('status', 'paid')->sum('amount');
        $available = max(0, $eligible - $securityReserve - $inFlight - $paid);

        return [
            'gross_credit' => round($gross, 2),
            'eligible_credit' => round($eligible, 2),
            'pending_sales' => round($pendingSales, 2),
            'security_reserve' => round($securityReserve, 2),
            'in_flight' => round($inFlight, 2),
            'paid_out' => round($paid, 2),
            'available' => round($available, 2),
            'reserve_percent' => $reservePercent,
        ];
    }

    public function requestPayout(User $user, float $requestedAmount): array
    {
        $amount = round($requestedAmount, 2);
        if ($amount <= 0) throw ValidationException::withMessages(['amount' => 'Informe um valor válido para receber.']);
        if (! $this->payoutProvider->isConfigured()) throw new RuntimeException('O serviço de repasses Pix ainda não está configurado para operação.');

        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        $destination = $this->destination((int) $user->id, true);
        if (! $beneficiary || $beneficiary->status !== 'verified') throw ValidationException::withMessages(['identity' => 'Sua identidade financeira ainda não está verificada.']);
        if (! $destination || $destination->status !== 'active') throw ValidationException::withMessages(['pix_key' => 'Cadastre e ative uma chave Pix antes de solicitar o recebimento.']);
        $balance = $this->balance($user);
        if ($amount > (float) $balance['available'] + 0.00001) throw ValidationException::withMessages(['amount' => 'O valor solicitado é maior que o saldo disponível.']);

        $stepUp = max(0, (float) config('services.finance.step_up_amount', 5000));
        $reverifyHours = max(1, (int) config('services.identity.reverify_hours', 24));
        $verifiedAt = $beneficiary->verified_at ? \Illuminate\Support\Carbon::parse($beneficiary->verified_at) : null;
        if ($stepUp > 0 && $amount >= $stepUp && (! $verifiedAt || $verifiedAt->lt(now()->subHours($reverifyHours)))) {
            throw ValidationException::withMessages(['identity' => 'Por segurança, este valor exige uma nova prova de vida antes do recebimento.']);
        }
        if ($this->payoutProvider->availableBalance() + 0.00001 < $amount) throw new RuntimeException('O repasse está temporariamente aguardando liquidação operacional. Tente novamente mais tarde.');

        $payout = DB::transaction(function () use ($user, $beneficiary, $destination, $amount) {
            $reference = (string) Str::uuid();
            $id = DB::table('financial_payouts')->insertGetId([
                'app_slug' => $this->context->slug(),
                'source_type' => 'user', 'source_id' => $user->id,
                'beneficiary_id' => $beneficiary->id,
                'payout_destination_id' => $destination->id,
                'requested_by_user_id' => $user->id,
                'reference' => $reference,
                'provider' => $this->payoutProvider->name(), 'status' => 'pending', 'amount' => $amount,
                'idempotency_key' => (string) Str::uuid(), 'risk_status' => 'approved',
                'metadata' => json_encode(['pix_key_masked' => $destination->pix_key_masked, 'requested_ip' => request()->ip(), 'user_agent_hash' => hash('sha256', (string) request()->userAgent())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('financial_payouts')->find($id);
        });

        try {
            $pixKey = Crypt::decryptString($destination->pix_key);
            $remote = $this->payoutProvider->transferPix($payout->reference, (float) $payout->amount, $pixKey, $destination->pix_key_type, 'Repasse Peter Tecnet');
            DB::table('financial_payouts')->where('id', $payout->id)->update([
                'provider_transfer_id' => (string) ($remote['id'] ?? ''), 'status' => 'processing', 'processing_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            DB::table('financial_payouts')->where('id', $payout->id)->update(['status' => 'provider_unknown', 'failed_at' => now(), 'updated_at' => now()]);
            throw new RuntimeException('Não foi possível confirmar o envio do Pix. O valor continua reservado para evitar pagamento duplicado.', 0, $e);
        }

        return ['message' => 'Repasse iniciado.', 'payout' => DB::table('financial_payouts')->find($payout->id), 'balance' => $this->balance($user)];
    }

    private function destination(int $userId, bool $activateExpiredCooling = false): ?object
    {
        $destination = DB::table('financial_payout_destinations')->where('source_type', 'user')->where('source_id', $userId)->first();
        if ($activateExpiredCooling && $destination && $destination->status === 'cooling' && $destination->cooling_until && now()->greaterThanOrEqualTo($destination->cooling_until)) {
            DB::table('financial_payout_destinations')->where('id', $destination->id)->update(['status' => 'active', 'updated_at' => now()]);
            return DB::table('financial_payout_destinations')->find($destination->id);
        }
        return $destination;
    }

    private function destinationPayload(object $destination): array
    {
        return [
            'id' => $destination->id, 'provider' => $destination->provider, 'type' => $destination->type,
            'pix_key_type' => $destination->pix_key_type, 'pix_key_masked' => $destination->pix_key_masked,
            'holder_name' => $destination->holder_name, 'holder_document_masked' => $destination->holder_document_masked,
            'status' => $destination->status, 'verified_at' => $destination->verified_at,
            'cooling_until' => $destination->cooling_until, 'changed_at' => $destination->changed_at,
        ];
    }

    private function documentMatches(string $remote, string $verified): bool
    {
        $remoteDigits = preg_replace('/\D+/', '', $remote);
        $verifiedDigits = preg_replace('/\D+/', '', $verified);
        if ($remoteDigits === '' || $verifiedDigits === '') return false;
        if (strlen($remoteDigits) >= 11) return hash_equals(substr($verifiedDigits, -strlen($remoteDigits)), $remoteDigits);
        return strlen($remoteDigits) >= 4 && hash_equals(substr($verifiedDigits, -strlen($remoteDigits)), $remoteDigits);
    }

    private function maskPixKey(string $type, string $key): string
    {
        if (in_array($type, ['CPF', 'CNPJ', 'PHONE'], true)) {
            $digits = preg_replace('/\D+/', '', $key);
            return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
        }
        if ($type === 'EMAIL' && str_contains($key, '@')) {
            [$name, $domain] = explode('@', $key, 2);
            return mb_substr($name, 0, 2).str_repeat('*', max(2, mb_strlen($name) - 2)).'@'.$domain;
        }
        return mb_substr($key, 0, 4).'…'.mb_substr($key, -4);
    }
}
