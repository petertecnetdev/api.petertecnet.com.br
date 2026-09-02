<?php

namespace App\Domain\Events\Services;

use App\Models\AdmissionCredential;
use App\Models\CheckIn;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CheckInService
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function checkIn(string $credentialCode, ?int $actorUserId = null, ?string $deviceId = null, ?string $requestId = null): CheckIn
    {
        [$publicId, $secret] = array_pad(explode('.', trim($credentialCode), 2), 2, null);
        if (! $publicId || ! $secret) {
            throw ValidationException::withMessages(['credential' => 'Credencial inválida.']);
        }

        return DB::transaction(function () use ($publicId, $secret, $actorUserId, $deviceId, $requestId) {
            $credential = AdmissionCredential::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            $validSecret = hash_equals((string) $credential->code_hash, hash('sha256', $secret));
            if (! $validSecret) {
                throw ValidationException::withMessages(['credential' => 'Credencial inválida.']);
            }

            if ($credential->status !== 'valid') {
                throw ValidationException::withMessages(['credential' => 'Esta credencial não está válida para entrada.']);
            }

            if ($credential->valid_from && now()->lt($credential->valid_from)) {
                throw ValidationException::withMessages(['credential' => 'Esta credencial ainda não está válida.']);
            }

            if ($credential->valid_until && now()->gt($credential->valid_until)) {
                throw ValidationException::withMessages(['credential' => 'Esta credencial expirou.']);
            }

            if ($credential->checked_in_at) {
                throw ValidationException::withMessages(['credential' => 'Esta credencial já foi utilizada.']);
            }

            $checkedInAt = now();
            $credential->forceFill([
                'status' => 'used',
                'checked_in_at' => $checkedInAt,
            ])->save();

            return CheckIn::create([
                'application_id' => $this->applicationContext->id(),
                'admission_credential_id' => $credential->id,
                'actor_user_id' => $actorUserId,
                'result' => 'accepted',
                'device_id' => $deviceId,
                'request_id' => $requestId,
                'checked_in_at' => $checkedInAt,
            ]);
        }, 3);
    }
}
