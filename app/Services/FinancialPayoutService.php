<?php

namespace App\Services;

use App\Models\Production;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class FinancialPayoutService
{
    public function __construct(
        private FinancialIdentityService $identity,
        private AsaasPayoutService $asaas,
    ) {}

    public function overview(Production $production, User $user): array
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        $identityOverview = $this->identity->overview($user);
        $identityReady = (bool) ($identityOverview['ready_for_pix'] ?? false);
        $destination = DB::table('financial_payout_destinations')
            ->where('source_type', 'production')
            ->where('source_id', $production->id)
            ->first();

        if ($destination && $destination->status === 'cooling' && $destination->cooling_until && now()->greaterThanOrEqualTo($destination->cooling_until)) {
            DB::table('financial_payout_destinations')->where('id', $destination->id)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
            $destination = DB::table('financial_payout_destinations')->find($destination->id);
        }

        $history = DB::table('financial_payouts')
            ->where('source_type', 'production')
            ->where('source_id', $production->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
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
            'identity' => $identityOverview,
            'destination' => $destination ? [
                'id' => $destination->id,
                'provider' => $destination->provider,
                'type' => $destination->type,
                'pix_key_type' => $destination->pix_key_type,
                'pix_key_masked' => $destination->pix_key_masked,
                'holder_name' => $destination->holder_name,
                'holder_document_masked' => $destination->holder_document_masked,
                'status' => $destination->status,
                'verified_at' => $destination->verified_at,
                'cooling_until' => $destination->cooling_until,
                'changed_at' => $destination->changed_at,
            ] : null,
            'balance' => $this->balance($production),
            'payouts' => $history,
            'payout_provider' => 'asaas',
            'ready_for_sales' => (bool) ($identityReady && $destination && in_array($destination->status, ['active', 'cooling'], true)),
            'ready_for_payout' => (bool) ($beneficiary && $beneficiary->status === 'verified' && $destination && $destination->status === 'active'),
        ];
    }

    public function savePixDestination(Production $production, User $user, string $type, string $key): array
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        if (!$beneficiary || !(bool) ($this->identity->overview($user)['ready_for_pix'] ?? false)) {
            throw ValidationException::withMessages(['identity' => 'Conclua a verificação de identidade antes de cadastrar a chave Pix.']);
        }

        $type = strtoupper(trim($type));
        if (!in_array($type, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
            throw ValidationException::withMessages(['pix_key_type' => 'Tipo de chave Pix inválido.']);
        }

        $normalizedKey = $this->asaas->normalizePixKey($type, $key);
        if ($normalizedKey === '') {
            throw ValidationException::withMessages(['pix_key' => 'Informe uma chave Pix válida.']);
        }

        $lookup = $this->asaas->lookupPixKey($type, $normalizedKey);
        $ownerName = trim((string) (data_get($lookup, 'owner.name') ?: data_get($lookup, 'name', '')));
        $ownerDocumentMasked = trim((string) (data_get($lookup, 'owner.cpfCnpj') ?: data_get($lookup, 'cpfCnpj', '')));
        $beneficiaryDocument = $this->identity->decryptedDocument($beneficiary);

        if (!$this->maskedDocumentMatches($ownerDocumentMasked, $beneficiaryDocument)) {
            throw ValidationException::withMessages([
                'pix_key' => 'A chave Pix informada não pertence ao CPF verificado deste produtor.',
            ]);
        }

        $now = now();
        $existing = DB::table('financial_payout_destinations')
            ->where('source_type', 'production')
            ->where('source_id', $production->id)
            ->first();
        $sameKey = $existing && hash_equals((string) $existing->pix_key_hash, $this->identity->fingerprint($type . ':' . $normalizedKey));
        $coolingHours = max(0, (int) config('services.finance.payout_destination_cooling_hours', 24));
        $isReplacement = (bool) ($existing && !$sameKey);
        $status = $isReplacement && $coolingHours > 0 ? 'cooling' : 'active';
        $coolingUntil = $status === 'cooling' ? $now->copy()->addHours($coolingHours) : null;

        $snapshot = [
            'owner' => [
                'name' => $ownerName,
                'cpfCnpj' => $ownerDocumentMasked,
            ],
            'financialInstitution' => data_get($lookup, 'financialInstitution'),
            'ispbName' => data_get($lookup, 'ispbName'),
            'verified_at' => $now->toIso8601String(),
        ];

        DB::table('financial_payout_destinations')->updateOrInsert(
            ['source_type' => 'production', 'source_id' => $production->id],
            [
                'beneficiary_id' => $beneficiary->id,
                'provider' => 'asaas',
                'type' => 'pix',
                'pix_key_type' => $type,
                'pix_key' => Crypt::encryptString($normalizedKey),
                'pix_key_hash' => $this->identity->fingerprint($type . ':' . $normalizedKey),
                'pix_key_masked' => $this->maskPixKey($type, $normalizedKey),
                'holder_name' => $ownerName !== '' ? $ownerName : $beneficiary->legal_name,
                'holder_document_masked' => $ownerDocumentMasked,
                'status' => $status,
                'provider_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'verified_at' => $now,
                'cooling_until' => $coolingUntil,
                'changed_at' => $isReplacement ? $now : ($existing?->changed_at ?? $now),
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        return $this->overview($production, $user);
    }

    public function requestPayout(Production $production, User $user, float $requestedAmount): array
    {
        $amount = round($requestedAmount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido para receber.']);
        }

        $payout = DB::transaction(function () use ($production, $user, $amount) {
            Production::query()->whereKey($production->id)->lockForUpdate()->firstOrFail();

            $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$beneficiary || $beneficiary->status !== 'verified') {
                throw ValidationException::withMessages(['identity' => 'Sua identidade financeira ainda não está verificada para liberar repasses.']);
            }

            $destination = DB::table('financial_payout_destinations')
                ->where('source_type', 'production')
                ->where('source_id', $production->id)
                ->lockForUpdate()
                ->first();
            if (!$destination) {
                throw ValidationException::withMessages(['pix_key' => 'Cadastre uma chave Pix antes de solicitar o recebimento.']);
            }
            if ($destination->status === 'cooling' && $destination->cooling_until && now()->lessThan($destination->cooling_until)) {
                throw ValidationException::withMessages(['pix_key' => 'A nova chave Pix está em período de segurança. Aguarde a liberação indicada na tela.']);
            }
            if ($destination->status === 'cooling') {
                DB::table('financial_payout_destinations')->where('id', $destination->id)->update(['status' => 'active', 'updated_at' => now()]);
                $destination->status = 'active';
            }
            if ($destination->status !== 'active') {
                throw ValidationException::withMessages(['pix_key' => 'A chave Pix não está ativa para recebimentos.']);
            }

            $balance = $this->balance($production);
            if ($amount > $balance['available'] + 0.00001) {
                throw ValidationException::withMessages(['amount' => 'O valor solicitado é maior que o saldo disponível.']);
            }

            $stepUpAmount = max(0, (float) config('services.finance.step_up_amount', 5000));
            $reverifyHours = max(1, (int) config('services.identity.reverify_hours', 24));
            $verifiedAt = $beneficiary->verified_at ? \Illuminate\Support\Carbon::parse($beneficiary->verified_at) : null;
            if ((bool) config('services.identity.liveness_required', false)
                && $stepUpAmount > 0
                && $amount >= $stepUpAmount
                && (!$verifiedAt || $verifiedAt->lt(now()->subHours($reverifyHours)))) {
                throw ValidationException::withMessages([
                    'identity' => 'Por segurança, este valor exige uma nova prova de vida antes do recebimento.',
                ]);
            }

            $reference = (string) Str::uuid();
            $id = DB::table('financial_payouts')->insertGetId([
                'app_slug' => $production->app_slug,
                'source_type' => 'production',
                'source_id' => $production->id,
                'beneficiary_id' => $beneficiary->id,
                'payout_destination_id' => $destination->id,
                'requested_by_user_id' => $user->id,
                'reference' => $reference,
                'provider' => 'asaas',
                'status' => 'pending',
                'amount' => $amount,
                'idempotency_key' => (string) Str::uuid(),
                'risk_status' => 'approved',
                'metadata' => json_encode([
                    'pix_key_masked' => $destination->pix_key_masked,
                    'holder_name' => $destination->holder_name,
                    'requested_ip' => request()->ip(),
                    'user_agent_hash' => hash('sha256', (string) request()->userAgent()),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('financial_payouts')->find($id);
        });

        try {
            $destination = DB::table('financial_payout_destinations')->find($payout->payout_destination_id);
            if (!$destination) throw new RuntimeException('Destino Pix do repasse não encontrado.');
            $pixKey = Crypt::decryptString($destination->pix_key);

            $remote = $this->asaas->transferPix(
                $payout->reference,
                (float) $payout->amount,
                $pixKey,
                $destination->pix_key_type,
                'Repasse Peter Tecnet - ' . ($production->name ?: ('Produção #' . $production->id))
            );

            DB::table('financial_payouts')->where('id', $payout->id)->update([
                'provider_transfer_id' => (string) $remote['id'],
                'status' => 'processing',
                'processing_at' => now(),
                'metadata' => $this->mergeMetadata($payout->metadata, [
                    'provider_status_at_creation' => $remote['status'] ?? null,
                ]),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            // A chamada pode ter falhado depois de o PSP aceitar a transferência.
            // Não liberamos o saldo automaticamente: reconciliação/manual ou webhook
            // deve resolver este estado sem risco de um Pix duplicado.
            DB::table('financial_payouts')->where('id', $payout->id)->update([
                'status' => 'provider_unknown',
                'failed_at' => now(),
                'metadata' => $this->mergeMetadata($payout->metadata, [
                    'provider_error' => Str::limit($e->getMessage(), 300),
                ]),
                'updated_at' => now(),
            ]);
            throw new RuntimeException('Não foi possível confirmar o envio do Pix. O valor continua reservado para evitar pagamento duplicado.', 0, $e);
        }

        return [
            'message' => 'Repasse iniciado. A confirmação final será feita automaticamente pelo provedor Pix.',
            'payout' => DB::table('financial_payouts')->find($payout->id),
            'balance' => $this->balance($production),
        ];
    }

    public function balance(Production $production): array
    {
        $query = DB::table('ledger_entries')
            ->where('production_id', $production->id)
            ->where('type', 'producer_credit')
            ->where('status', 'posted')
            ->where('metadata->settlement_mode', 'platform_collection');

        $grossCredit = (float) (clone $query)->sum('amount');
        $holdHours = max(0, (int) config('services.finance.payout_hold_hours', 24));
        $eligibleQuery = clone $query;
        if ($holdHours > 0) $eligibleQuery->where('created_at', '<=', now()->subHours($holdHours));
        $eligibleCredit = (float) $eligibleQuery->sum('amount');
        $pendingSales = max(0, $grossCredit - $eligibleCredit);
        $reservePercent = max(0, min((float) config('services.finance.payout_reserve_percent', 10), 100));
        $securityReserve = round($eligibleCredit * ($reservePercent / 100), 2);

        $newInFlight = (float) DB::table('financial_payouts')
            ->where('source_type', 'production')->where('source_id', $production->id)
            ->whereIn('status', ['pending', 'processing', 'provider_unknown'])->sum('amount');
        $newPaid = (float) DB::table('financial_payouts')
            ->where('source_type', 'production')->where('source_id', $production->id)
            ->where('status', 'paid')->sum('amount');

        // Compatibility records from the previous payout module are exposed
        // through the same generic storage during the expand/contract rollout.
        $legacyInFlight = (float) DB::table('payout_requests')
            ->where('production_id', $production->id)->whereIn('status', ['pending', 'processing'])->sum('amount');
        $legacyPaid = (float) DB::table('payout_requests')
            ->where('production_id', $production->id)->where('status', 'paid')->sum('amount');

        $inFlight = $newInFlight + $legacyInFlight;
        $paid = $newPaid + $legacyPaid;
        $available = max(0, $eligibleCredit - $securityReserve - $inFlight - $paid);

        return [
            'producer_credit' => round($grossCredit, 2),
            'pending_release' => round($pendingSales, 2),
            'eligible_credit' => round($eligibleCredit, 2),
            'security_reserve' => round($securityReserve, 2),
            'payout_pending' => round($inFlight, 2),
            'payout_paid' => round($paid, 2),
            'available' => round($available, 2),
            'hold_hours' => $holdHours,
            'reserve_percent' => $reservePercent,
        ];
    }

    public function processWebhook(array $payload): void
    {
        $eventId = trim((string) ($payload['id'] ?? ''));
        $eventType = strtoupper(trim((string) ($payload['event'] ?? '')));
        $transferId = trim((string) data_get($payload, 'transfer.id', ''));
        if ($eventId === '' || $transferId === '') return;

        $inserted = DB::table('financial_webhook_events')->insertOrIgnore([
            'provider' => 'asaas',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (!$inserted) return;

        DB::transaction(function () use ($payload, $eventId, $eventType, $transferId) {
            $payout = DB::table('financial_payouts')->where('provider_transfer_id', $transferId)->lockForUpdate()->first();
            if (!$payout) {
                DB::table('financial_webhook_events')->where('provider', 'asaas')->where('event_id', $eventId)->update(['processed_at' => now(), 'updated_at' => now()]);
                return;
            }

            $status = match ($eventType) {
                'TRANSFER_DONE' => 'paid',
                'TRANSFER_FAILED' => 'failed',
                'TRANSFER_CANCELLED' => 'cancelled',
                default => 'processing',
            };

            $updates = [
                'status' => $status,
                'metadata' => $this->mergeMetadata($payout->metadata, [
                    'last_provider_event' => $eventType,
                    'provider_status' => data_get($payload, 'transfer.status'),
                    'provider_fail_reason' => data_get($payload, 'transfer.failReason'),
                ]),
                'updated_at' => now(),
            ];
            if ($status === 'paid') $updates['paid_at'] = now();
            if ($status === 'failed') $updates['failed_at'] = now();
            if ($status === 'cancelled') $updates['cancelled_at'] = now();

            DB::table('financial_payouts')->where('id', $payout->id)->update($updates);
            DB::table('financial_webhook_events')->where('provider', 'asaas')->where('event_id', $eventId)->update(['processed_at' => now(), 'updated_at' => now()]);
        });
    }

    private function maskedDocumentMatches(string $masked, string $document): bool
    {
        $pattern = preg_replace('/[^0-9*]/', '', $masked);
        $digits = preg_replace('/\D+/', '', $document);
        if ($pattern === '' || strlen($pattern) !== strlen($digits)) return false;
        for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
            if ($pattern[$i] !== '*' && $pattern[$i] !== $digits[$i]) return false;
        }
        return true;
    }

    private function maskPixKey(string $type, string $key): string
    {
        return match ($type) {
            'CPF' => '***.' . substr($key, 3, 3) . '.' . substr($key, 6, 3) . '-**',
            'CNPJ' => '**.***.' . substr($key, 5, 3) . '/' . substr($key, 8, 4) . '-**',
            'EMAIL' => $this->maskEmail($key),
            'PHONE' => '(**) *****-' . substr($key, -4),
            default => substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4),
        };
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') return str_repeat('*', max(1, strlen($email) - 3)) . substr($email, -3);
        return mb_substr($local, 0, 2) . '***@' . $domain;
    }

    private function mergeMetadata(?string $current, array $new): string
    {
        $decoded = $current ? json_decode($current, true) : [];
        if (!is_array($decoded)) $decoded = [];
        return json_encode(array_merge($decoded, $new), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
