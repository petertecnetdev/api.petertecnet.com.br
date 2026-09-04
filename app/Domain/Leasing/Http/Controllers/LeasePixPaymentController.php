<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Finance\Services\PixBrCodeService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class LeasePixPaymentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PixBrCodeService $pix,
    ) {}

    public function profile(Request $request)
    {
        $userId = (int) $request->user()->id;
        $profile = DB::table('payment_receiving_profiles')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('method', 'pix')
            ->where('is_active', true)
            ->first();

        return response()->json([
            'can_receive' => $this->canReceive($userId),
            'profile' => $profile ? $this->publicProfile($profile) : null,
        ]);
    }

    public function saveProfile(Request $request)
    {
        $userId = (int) $request->user()->id;
        abort_unless($this->canReceive($userId), 403, 'Cadastre um imóvel antes de configurar o recebimento PIX.');

        $data = $request->validate([
            'pix_key_type' => 'required|in:cpf,cnpj,email,phone,random',
            'pix_key' => 'required|string|max:190',
            'holder_name' => 'required|string|min:2|max:190',
            'merchant_city' => 'required|string|min:2|max:80',
        ]);

        try {
            $normalized = $this->pix->normalizeKey($data['pix_key_type'], $data['pix_key']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $values = [
            'pix_key_type' => $data['pix_key_type'],
            'pix_key' => Crypt::encryptString($normalized),
            'pix_key_hash' => hash('sha256', $normalized),
            'pix_key_masked' => $this->pix->maskKey($data['pix_key_type'], $normalized),
            'holder_name' => trim($data['holder_name']),
            'merchant_city' => trim($data['merchant_city']),
            'is_active' => true,
            'updated_at' => now(),
        ];

        $existing = DB::table('payment_receiving_profiles')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('method', 'pix')
            ->first();

        if ($existing) {
            DB::table('payment_receiving_profiles')->where('id', $existing->id)->update($values);
            $profileId = (int) $existing->id;
        } else {
            $profileId = (int) DB::table('payment_receiving_profiles')->insertGetId([
                'app_id' => $this->context->id(),
                'user_id' => $userId,
                'method' => 'pix',
                ...$values,
                'created_at' => now(),
            ]);
        }

        $profile = DB::table('payment_receiving_profiles')->where('id', $profileId)->first();

        return response()->json([
            'message' => 'Chave PIX salva para recebimentos.',
            'profile' => $this->publicProfile($profile),
        ]);
    }

    public function prepare(Request $request, int $leaseId, int $chargeId)
    {
        $data = $request->validate([
            'method' => 'required|in:pix,boleto,card',
        ]);

        // Preserva os meios já existentes enquanto o fluxo de recebimento direto
        // é aplicado somente ao PIX.
        if ($data['method'] !== 'pix') {
            return app(LeasingController::class)->preparePayment($request, $leaseId, $chargeId);
        }

        $user = $request->user();
        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($lease, 404, 'Locação não encontrada.');

        $isTenant = (int) ($lease->tenant_user_id ?? 0) === (int) $user->id
            || (!$lease->tenant_user_id && $lease->tenant_email && strcasecmp((string) $lease->tenant_email, (string) $user->email) === 0);
        abort_unless($isTenant, 403, 'Somente o inquilino vinculado pode gerar o PIX desta cobrança.');

        if (!$lease->tenant_user_id) {
            DB::table('leases')->where('id', $lease->id)->update([
                'tenant_user_id' => $user->id,
                'updated_at' => now(),
            ]);
        }

        $charge = DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->where('id', $chargeId)
            ->first();
        abort_unless($charge, 404, 'Cobrança não encontrada.');
        abort_if($charge->status === 'paid', 422, 'Esta cobrança já foi paga.');

        $profile = DB::table('payment_receiving_profiles')
            ->where('app_id', $this->context->id())
            ->where('user_id', $lease->landlord_user_id)
            ->where('method', 'pix')
            ->where('is_active', true)
            ->first();
        abort_unless($profile, 422, 'O proprietário ainda não cadastrou uma chave PIX para receber esta cobrança.');

        try {
            $pixKey = Crypt::decryptString((string) $profile->pix_key);
        } catch (Throwable) {
            abort(422, 'A chave PIX cadastrada precisa ser atualizada pelo proprietário.');
        }

        $txid = $charge->pix_txid ?: substr('LOC'.preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $charge->public_id)), 0, 25);

        try {
            $payload = $this->pix->generate(
                $pixKey,
                (string) $profile->holder_name,
                (string) $profile->merchant_city,
                (float) $charge->amount,
                $txid,
                (string) $charge->description,
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $snapshot = [
            'profile_id' => (int) $profile->id,
            'landlord_user_id' => (int) $lease->landlord_user_id,
            'method' => 'pix',
            'pix_key_type' => $profile->pix_key_type,
            'pix_key_masked' => $profile->pix_key_masked,
            'holder_name' => $profile->holder_name,
            'merchant_city' => $profile->merchant_city,
            'captured_at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($charge, $lease, $user, $profile, $payload, $snapshot, $txid) {
            $payment = DB::table('ecosystem_payments')
                ->where('app_slug', $this->context->slug())
                ->where('source_type', 'lease_charge')
                ->where('source_reference', $charge->public_id)
                ->first();

            $paymentValues = [
                'app_id' => $this->context->id(),
                'app_slug' => $this->context->slug(),
                'provider' => 'pix_direct',
                'provider_payment_id' => $txid,
                'source_type' => 'lease_charge',
                'source_reference' => $charge->public_id,
                'source_id' => $charge->id,
                'user_id' => $user->id,
                'currency' => 'BRL',
                'method' => 'pix',
                'status' => 'pending',
                'gross_amount' => $charge->amount,
                'platform_fee' => 0,
                'provider_fee' => 0,
                'seller_net' => $charge->amount,
                'metadata' => json_encode([
                    'lease_id' => (int) $lease->id,
                    'recipient' => $snapshot,
                    'pix_txid' => $txid,
                    'confirmation_mode' => 'landlord_manual',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];

            if ($payment) {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update($paymentValues);
                $paymentId = (int) $payment->id;
            } else {
                $paymentId = (int) DB::table('ecosystem_payments')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    ...$paymentValues,
                    'created_at' => now(),
                ]);
            }

            DB::table('lease_charges')->where('id', $charge->id)->update([
                'ecosystem_payment_id' => $paymentId,
                'payment_receiving_profile_id' => $profile->id,
                'payment_method' => 'pix',
                'provider' => 'pix_direct',
                'provider_payment_id' => $txid,
                'pix_txid' => $txid,
                'pix_payload' => $payload,
                'payment_recipient_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'processing',
                'updated_at' => now(),
            ]);
        });

        $freshCharge = DB::table('lease_charges')->where('id', $chargeId)->first();

        return response()->json([
            'payment' => DB::table('ecosystem_payments')->where('id', $freshCharge->ecosystem_payment_id)->first(),
            'charge' => $freshCharge,
            'pix_copy_paste' => $payload,
            'copy_paste' => $payload,
            'qr_code_text' => $payload,
            'pix' => [
                'copy_paste' => $payload,
                'txid' => $txid,
                'key_type' => $profile->pix_key_type,
                'key_masked' => $profile->pix_key_masked,
                'holder_name' => $profile->holder_name,
                'merchant_city' => $profile->merchant_city,
            ],
            'provider_checkout_required' => false,
            'confirmation_mode' => 'landlord_manual',
            'message' => 'PIX gerado para pagamento direto ao proprietário.',
        ]);
    }

    private function canReceive(int $userId): bool
    {
        return DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->exists()
            || DB::table('leases')
                ->where('app_id', $this->context->id())
                ->where('landlord_user_id', $userId)
                ->whereNull('deleted_at')
                ->exists();
    }

    private function publicProfile(object $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'method' => $profile->method,
            'pix_key_type' => $profile->pix_key_type,
            'pix_key_masked' => $profile->pix_key_masked,
            'holder_name' => $profile->holder_name,
            'merchant_city' => $profile->merchant_city,
            'is_active' => (bool) $profile->is_active,
            'updated_at' => $profile->updated_at,
        ];
    }
}
