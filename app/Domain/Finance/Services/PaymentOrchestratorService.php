<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PaymentOrchestratorService
{
    public function __construct(
        private readonly PixBrCodeService $pix,
        private readonly PaymentReceivingProfileService $profiles,
    ) {}

    public function prepareProvider(array $input): object
    {
        return DB::transaction(function () use ($input) {
            $payment = $this->sourceQuery($input)->lockForUpdate()->first();
            $values = $this->paymentValues($input, [
                'provider' => $input['provider'],
                'provider_payment_id' => $input['provider_payment_id'] ?? null,
                'status' => 'pending',
            ]);

            if ($payment) {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update($values);
                $id = (int) $payment->id;
            } else {
                $id = (int) DB::table('ecosystem_payments')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    ...$values,
                    'created_at' => now(),
                ]);
            }

            return DB::table('ecosystem_payments')->where('id', $id)->firstOrFail();
        });
    }

    public function prepareDirectPix(array $input): array
    {
        $recipient = $this->profiles->decryptedPix((int) $input['app_id'], (int) $input['recipient_user_id']);
        $profile = $recipient['profile'];
        $txid = $input['txid'] ?: substr('PAY'.preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $input['source_reference'])), 0, 25);

        try {
            $payload = $this->pix->generate(
                $recipient['key'],
                (string) $profile->holder_name,
                (string) $profile->merchant_city,
                (float) $input['amount'],
                $txid,
                $input['description'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $snapshot = [
            'profile_id' => (int) $profile->id,
            'recipient_user_id' => (int) $input['recipient_user_id'],
            'method' => 'pix',
            'pix_key_type' => $profile->pix_key_type,
            'pix_key_masked' => $profile->pix_key_masked,
            'holder_name' => $profile->holder_name,
            'merchant_city' => $profile->merchant_city,
            'captured_at' => now()->toIso8601String(),
        ];

        $metadata = array_merge($input['metadata'] ?? [], [
            'recipient' => $snapshot,
            'pix_txid' => $txid,
            'confirmation_mode' => $input['confirmation_mode'] ?? 'recipient_manual',
        ]);

        $payment = $this->prepareProvider(array_merge($input, [
            'method' => 'pix',
            'provider' => 'pix_direct',
            'provider_payment_id' => $txid,
            'metadata' => $metadata,
        ]));

        return [
            'payment' => $payment,
            'payload' => $payload,
            'txid' => $txid,
            'profile' => $profile,
            'recipient_snapshot' => $snapshot,
        ];
    }

    public function markPaid(array $input): object
    {
        return DB::transaction(function () use ($input) {
            $payment = $this->sourceQuery($input)->lockForUpdate()->first();
            $values = $this->paymentValues($input, [
                'provider' => $input['provider'] ?? 'manual',
                'provider_payment_id' => $input['provider_payment_id'] ?? null,
                'status' => 'paid',
                'paid_at' => $input['paid_at'] ?? now(),
            ]);

            if ($payment) {
                DB::table('ecosystem_payments')->where('id', $payment->id)->update($values);
                $id = (int) $payment->id;
            } else {
                $id = (int) DB::table('ecosystem_payments')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    ...$values,
                    'created_at' => now(),
                ]);
            }

            return DB::table('ecosystem_payments')->where('id', $id)->firstOrFail();
        });
    }

    private function sourceQuery(array $input)
    {
        return DB::table('ecosystem_payments')
            ->where('app_id', $input['app_id'])
            ->where('app_slug', $input['app_slug'])
            ->where('source_type', $input['source_type'])
            ->where('source_reference', $input['source_reference']);
    }

    private function paymentValues(array $input, array $overrides): array
    {
        return array_merge([
            'app_id' => $input['app_id'],
            'app_slug' => $input['app_slug'],
            'source_type' => $input['source_type'],
            'source_reference' => $input['source_reference'],
            'source_id' => $input['source_id'] ?? null,
            'user_id' => $input['user_id'] ?? null,
            'currency' => $input['currency'] ?? 'BRL',
            'method' => $input['method'],
            'gross_amount' => $input['amount'],
            'platform_fee' => $input['platform_fee'] ?? 0,
            'provider_fee' => $input['provider_fee'] ?? 0,
            'seller_net' => $input['seller_net'] ?? $input['amount'],
            'metadata' => $this->json($input['metadata'] ?? []),
            'updated_at' => now(),
        ], $overrides);
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
