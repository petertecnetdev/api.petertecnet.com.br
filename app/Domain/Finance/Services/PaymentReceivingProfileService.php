<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class PaymentReceivingProfileService
{
    public function __construct(private readonly PixBrCodeService $pix) {}

    public function active(int $appId, int $userId, string $method = 'pix'): ?object
    {
        return DB::table('payment_receiving_profiles')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('method', $method)
            ->where('is_active', true)
            ->first();
    }

    public function publicProfile(?object $profile): ?array
    {
        if (! $profile) {
            return null;
        }

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

    public function savePix(int $appId, int $userId, array $data): object
    {
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

        $profileId = DB::transaction(function () use ($appId, $userId, $values) {
            $existing = DB::table('payment_receiving_profiles')
                ->where('app_id', $appId)
                ->where('user_id', $userId)
                ->where('method', 'pix')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('payment_receiving_profiles')
                    ->where('id', $existing->id)
                    ->where('app_id', $appId)
                    ->update($values);

                return (int) $existing->id;
            }

            return (int) DB::table('payment_receiving_profiles')->insertGetId([
                'app_id' => $appId,
                'user_id' => $userId,
                'method' => 'pix',
                ...$values,
                'created_at' => now(),
            ]);
        });

        return DB::table('payment_receiving_profiles')
            ->where('id', $profileId)
            ->where('app_id', $appId)
            ->firstOrFail();
    }

    public function decryptedPix(int $appId, int $userId): array
    {
        $profile = $this->active($appId, $userId, 'pix');
        abort_unless($profile, 422, 'O recebedor ainda não cadastrou uma chave PIX para esta cobrança.');

        try {
            $key = Crypt::decryptString((string) $profile->pix_key);
        } catch (Throwable) {
            abort(422, 'A chave PIX cadastrada precisa ser atualizada pelo recebedor.');
        }

        return ['profile' => $profile, 'key' => $key];
    }
}
