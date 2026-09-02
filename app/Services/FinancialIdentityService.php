<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FinancialIdentityService
{
    public function __construct(private AwsFaceVerificationService $faceVerification) {}

    public function overview(User $user): array
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        $verification = $beneficiary
            ? DB::table('identity_verifications')->where('beneficiary_id', $beneficiary->id)->latest('id')->first()
            : null;

        $profileName = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));
        $profileCpf = preg_replace('/\D+/', '', (string) $user->cpf);

        return [
            'beneficiary' => $beneficiary ? [
                'id' => $beneficiary->id,
                'legal_name' => $beneficiary->legal_name,
                'document_type' => $beneficiary->document_type,
                'document_masked' => $this->maskDocument($this->decrypt($beneficiary->document_number)),
                'birthdate' => $beneficiary->birthdate,
                'status' => $beneficiary->status,
                'verification_level' => $beneficiary->verification_level,
                'verified_at' => $beneficiary->verified_at,
            ] : null,
            'profile_prefill' => [
                'legal_name' => $profileName,
                'document_type' => 'CPF',
                'document_number' => $profileCpf !== '' ? $this->maskDocument($profileCpf) : null,
                'birthdate' => optional($user->birthdate)->format('Y-m-d'),
                'has_document_number' => strlen($profileCpf) === 11,
            ],
            'verification' => $verification ? [
                'id' => $verification->id,
                'status' => $verification->status,
                'document_type' => $verification->document_type,
                'document_status' => $verification->document_status,
                'document_front_uploaded' => !empty($verification->document_front_path),
                'document_back_uploaded' => !empty($verification->document_back_path),
                'liveness_status' => $verification->liveness_status,
                'face_match_status' => $verification->face_match_status,
                'face_similarity' => $verification->face_similarity,
                'liveness_confidence' => $verification->liveness_confidence,
                'rejection_reason' => $verification->rejection_reason,
                'verified_at' => $verification->verified_at,
            ] : null,
            'ready_for_pix' => (bool) ($beneficiary && $beneficiary->status === 'verified'),
            'next_action' => $this->nextAction($beneficiary, $verification),
        ];
    }

    public function saveProfile(User $user, array $data): array
    {
        $legalName = trim((string) ($data['legal_name'] ?? ''));
        $documentType = strtoupper(trim((string) ($data['document_type'] ?? 'CPF')));
        $document = preg_replace('/\D+/', '', (string) ($data['document_number'] ?? ''));
        $birthdate = $data['birthdate'] ?? null;

        if ($legalName === '') {
            $legalName = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));
        }
        if ($document === '') {
            $document = preg_replace('/\D+/', '', (string) $user->cpf);
        }
        if (!$birthdate && $user->birthdate) {
            $birthdate = $user->birthdate->format('Y-m-d');
        }

        if ($legalName === '' || mb_strlen($legalName) < 5) {
            throw ValidationException::withMessages(['legal_name' => 'Informe seu nome civil completo.']);
        }
        if ($documentType !== 'CPF') {
            throw ValidationException::withMessages(['document_type' => 'Nesta etapa de produção, a verificação automática aceita CPF do titular.']);
        }
        if (!$this->validCpf($document)) {
            throw ValidationException::withMessages(['document_number' => 'Informe um CPF válido.']);
        }
        if (!$birthdate) {
            throw ValidationException::withMessages(['birthdate' => 'Informe sua data de nascimento.']);
        }

        $fingerprint = $this->fingerprint($document);
        $conflict = DB::table('financial_beneficiaries')
            ->where('document_number_hash', $fingerprint)
            ->where('user_id', '<>', $user->id)
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['document_number' => 'Este CPF já está vinculado a outra identidade financeira.']);
        }

        DB::transaction(function () use ($user, $legalName, $documentType, $document, $fingerprint, $birthdate) {
            $current = DB::table('financial_beneficiaries')->where('user_id', $user->id)->lockForUpdate()->first();
            $identityChanged = !$current
                || $current->document_number_hash !== $fingerprint
                || mb_strtolower(trim((string) $current->legal_name)) !== mb_strtolower($legalName);

            $payload = [
                'legal_name' => $legalName,
                'document_type' => $documentType,
                'document_number' => Crypt::encryptString($document),
                'document_number_hash' => $fingerprint,
                'birthdate' => $birthdate,
                'updated_at' => now(),
            ];

            if ($identityChanged) {
                $payload['status'] = 'pending';
                $payload['verification_level'] = 'profile';
                $payload['verified_at'] = null;
            }

            if ($current) {
                DB::table('financial_beneficiaries')->where('id', $current->id)->update($payload);
            } else {
                DB::table('financial_beneficiaries')->insert(array_merge($payload, [
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'verification_level' => 'profile',
                    'created_at' => now(),
                ]));
            }

            $userUpdates = [];
            if (!$user->cpf) $userUpdates['cpf'] = $document;
            if (!$user->birthdate) $userUpdates['birthdate'] = $birthdate;
            if ($userUpdates !== []) $user->forceFill($userUpdates)->save();
        });

        return $this->overview($user->fresh());
    }

    public function uploadDocuments(User $user, UploadedFile $front, ?UploadedFile $back, bool $consent): array
    {
        if (!$consent) {
            throw ValidationException::withMessages(['consent' => 'É necessário autorizar o tratamento dos dados para verificação de identidade.']);
        }

        $beneficiary = $this->requireBeneficiary($user);
        $directory = 'private/identity/' . $user->id . '/' . date('Y/m');
        $frontPath = $front->store($directory, 'local');
        $backPath = $back?->store($directory, 'local');

        if (!$frontPath) throw new RuntimeException('Não foi possível armazenar o documento com segurança.');

        DB::transaction(function () use ($beneficiary, $frontPath, $backPath) {
            DB::table('identity_verifications')->insert([
                'beneficiary_id' => $beneficiary->id,
                'provider' => 'aws_rekognition',
                'status' => 'document_uploaded',
                'document_type' => 'identity_document',
                'document_front_path' => $frontPath,
                'document_back_path' => $backPath,
                'document_status' => 'uploaded',
                'liveness_status' => 'pending',
                'face_match_status' => 'pending',
                'consent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('financial_beneficiaries')->where('id', $beneficiary->id)->update([
                'status' => 'identity_pending',
                'verification_level' => 'document_uploaded',
                'verified_at' => null,
                'updated_at' => now(),
            ]);
        });

        return $this->overview($user);
    }

    public function startLiveness(User $user): array
    {
        $beneficiary = $this->requireBeneficiary($user);
        $verification = DB::table('identity_verifications')
            ->where('beneficiary_id', $beneficiary->id)
            ->whereNotNull('document_front_path')
            ->latest('id')
            ->first();

        if (!$verification) {
            throw ValidationException::withMessages(['document' => 'Envie primeiro uma foto nítida do seu documento com foto.']);
        }

        $session = $this->faceVerification->createSession((int) $user->id);
        DB::table('identity_verifications')->where('id', $verification->id)->update([
            'status' => 'liveness_pending',
            'liveness_session_id' => $session['session_id'],
            'liveness_status' => 'started',
            'updated_at' => now(),
        ]);

        return $session;
    }

    public function completeLiveness(User $user, string $sessionId): array
    {
        $beneficiary = $this->requireBeneficiary($user);
        $verification = DB::table('identity_verifications')
            ->where('beneficiary_id', $beneficiary->id)
            ->where('liveness_session_id', $sessionId)
            ->latest('id')
            ->first();

        if (!$verification || !$verification->document_front_path) {
            throw ValidationException::withMessages(['session_id' => 'Sessão de verificação inválida ou expirada.']);
        }

        $documentPath = Storage::disk('local')->path($verification->document_front_path);
        $result = $this->faceVerification->verify($sessionId, $documentPath);

        DB::transaction(function () use ($beneficiary, $verification, $result) {
            DB::table('identity_verifications')->where('id', $verification->id)->update([
                'status' => $result['passed'] ? 'verified' : 'rejected',
                'document_status' => $result['passed'] ? 'face_confirmed' : 'review_required',
                'liveness_status' => $result['liveness_status'],
                'face_match_status' => $result['face_match_status'],
                'face_similarity' => $result['face_similarity'],
                'liveness_confidence' => $result['liveness_confidence'],
                'rejection_reason' => $result['passed'] ? null : 'Não foi possível confirmar prova de vida e correspondência facial com o documento.',
                'verified_at' => $result['passed'] ? now() : null,
                'updated_at' => now(),
            ]);

            DB::table('financial_beneficiaries')->where('id', $beneficiary->id)->update([
                'status' => $result['passed'] ? 'verified' : 'identity_review_required',
                'verification_level' => $result['passed'] ? 'document_face_liveness' : 'document_uploaded',
                'verified_at' => $result['passed'] ? now() : null,
                'updated_at' => now(),
            ]);
        });

        return $this->overview($user);
    }

    public function decryptedDocument(object $beneficiary): string
    {
        return $this->decrypt((string) $beneficiary->document_number);
    }

    public function fingerprint(string $value): string
    {
        return hash_hmac('sha256', trim($value), (string) config('app.key'));
    }

    private function requireBeneficiary(User $user): object
    {
        $beneficiary = DB::table('financial_beneficiaries')->where('user_id', $user->id)->first();
        if (!$beneficiary) {
            throw ValidationException::withMessages(['identity' => 'Confirme primeiro seus dados de identidade.']);
        }
        return $beneficiary;
    }

    private function nextAction(?object $beneficiary, ?object $verification): string
    {
        if (!$beneficiary) return 'identity_profile';
        if (!$verification || !$verification->document_front_path) return 'document';
        if ($beneficiary->status !== 'verified') return 'liveness';
        return 'pix';
    }

    private function decrypt(?string $value): string
    {
        if (!$value) return '';
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function maskDocument(string $document): ?string
    {
        $digits = preg_replace('/\D+/', '', $document);
        if (strlen($digits) !== 11) return $digits !== '' ? str_repeat('*', max(strlen($digits) - 4, 0)) . substr($digits, -4) : null;
        return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
    }

    private function validCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D+/', '', $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) $sum += ((int) $cpf[$i]) * (($t + 1) - $i);
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) return false;
        }
        return true;
    }
}
