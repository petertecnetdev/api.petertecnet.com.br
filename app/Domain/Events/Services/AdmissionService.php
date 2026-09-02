<?php

namespace App\Domain\Events\Services;

use App\Models\AdmissionCredential;
use App\Models\AdmissionType;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdmissionService
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function createType(array $attributes): AdmissionType
    {
        return AdmissionType::create(array_merge($attributes, [
            'application_id' => $this->applicationContext->id(),
        ]));
    }

    public function issue(AdmissionType $type, ?int $userId, array $source = [], array $metadata = []): AdmissionCredential
    {
        abort_unless((int) $type->application_id === $this->applicationContext->id(), 404);

        return DB::transaction(function () use ($type, $userId, $source, $metadata) {
            $active = AdmissionCredential::query()
                ->where('admission_type_id', $type->id)
                ->whereNotIn('status', ['cancelled','refunded','revoked'])
                ->lockForUpdate()
                ->count();

            if ($type->capacity !== null) {
                abort_if($active >= (int) $type->capacity, 422, 'Não há mais admissões disponíveis.');
            }

            $publicId = (string) Str::uuid();
            $rawCode = Str::random(48);

            $credential = AdmissionCredential::create([
                'public_id' => $publicId,
                'application_id' => $this->applicationContext->id(),
                'admission_type_id' => $type->id,
                'user_id' => $userId,
                'status' => 'valid',
                'code_hash' => hash('sha256', $rawCode),
                'source_type' => $source['type'] ?? null,
                'source_id' => $source['id'] ?? null,
                'valid_from' => $source['valid_from'] ?? null,
                'valid_until' => $source['valid_until'] ?? null,
                'metadata' => $metadata,
            ]);

            // Raw QR material is returned once and never persisted in plaintext.
            $credential->setAttribute('credential_code', $publicId . '.' . $rawCode);
            return $credential;
        });
    }

    public function revoke(AdmissionCredential $credential, string $reason = 'revoked'): AdmissionCredential
    {
        $credential->forceFill([
            'status' => 'revoked',
            'metadata' => array_merge($credential->metadata ?? [], ['revocation_reason' => $reason]),
        ])->save();

        return $credential->fresh();
    }
}
