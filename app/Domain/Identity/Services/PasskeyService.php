<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityCredential;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasskeyService
{
    public function __construct(private readonly IdentityChallengeService $challenges)
    {
    }

    public function registrationOptions(User $user, Request $request, ?Application $application = null): array
    {
        $issued = $this->challenges->issue(
            'passkey_register',
            $user,
            $application,
            ['rp_id' => $this->rpId()],
            (int) config('identity.passkeys.challenge_ttl_minutes', 5),
            $request
        );

        return [
            'challenge' => $issued['token'],
            'rp' => [
                'name' => (string) config('identity.passkeys.rp_name', 'Peter Tecnet'),
                'id' => $this->rpId(),
            ],
            'user' => [
                'id' => $this->challenges->base64Url((string) $user->getKey()),
                'name' => (string) $user->email,
                'displayName' => trim((string) ($user->first_name . ' ' . $user->last_name)) ?: (string) $user->email,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => IdentityCredential::query()
                ->where('user_id', $user->id)
                ->where('type', 'passkey')
                ->get(['credential_id', 'transports'])
                ->map(fn ($credential) => [
                    'type' => 'public-key',
                    'id' => $credential->credential_id,
                    'transports' => $credential->transports ?: [],
                ])->values()->all(),
        ];
    }

    public function register(User $user, Request $request): IdentityCredential
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:255'],
            'credential_id' => ['required', 'string', 'max:700'],
            'client_data_json' => ['required', 'string'],
            'authenticator_data' => ['required', 'string'],
            'public_key' => ['required', 'string'],
            'algorithm' => ['required', 'integer', 'in:-7,-257'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'max:40'],
            'name' => ['nullable', 'string', 'max:180'],
        ]);

        $challenge = $this->challenges->consume('passkey_register', $data['challenge']);
        if (! $challenge || (int) $challenge->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['challenge' => 'Desafio de passkey inválido ou expirado.']);
        }

        $clientDataRaw = $this->decodeRequired($data['client_data_json'], 'client_data_json');
        $clientData = json_decode($clientDataRaw, true);
        $this->validateClientData($clientData, 'webauthn.create', $data['challenge']);

        $authenticatorData = $this->decodeRequired($data['authenticator_data'], 'authenticator_data');
        $this->validateAuthenticatorData($authenticatorData);

        $publicKey = $this->decodeRequired($data['public_key'], 'public_key');
        if (strlen($publicKey) < 64 || ! openssl_pkey_get_public($this->derToPem($publicKey))) {
            throw ValidationException::withMessages(['public_key' => 'Chave pública da passkey inválida.']);
        }

        $signCount = $this->signCount($authenticatorData);

        return IdentityCredential::query()->updateOrCreate(
            ['credential_id' => $data['credential_id']],
            [
                'user_id' => $user->id,
                'type' => 'passkey',
                'public_key' => base64_encode($publicKey),
                'algorithm' => (int) $data['algorithm'],
                'sign_count' => $signCount,
                'transports' => array_values(array_unique($data['transports'] ?? [])),
                'name' => $data['name'] ?: 'Passkey ' . now()->format('d/m/Y'),
                'last_used_at' => null,
            ]
        );
    }

    public function authenticationOptions(Request $request, ?Application $application = null): array
    {
        $issued = $this->challenges->issue(
            'passkey_authenticate',
            null,
            $application,
            ['rp_id' => $this->rpId()],
            (int) config('identity.passkeys.challenge_ttl_minutes', 5),
            $request
        );

        return [
            'challenge' => $issued['token'],
            'rpId' => $this->rpId(),
            'timeout' => 60000,
            'userVerification' => 'required',
        ];
    }

    public function authenticate(Request $request): User
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:255'],
            'credential_id' => ['required', 'string', 'max:700'],
            'client_data_json' => ['required', 'string'],
            'authenticator_data' => ['required', 'string'],
            'signature' => ['required', 'string'],
        ]);

        $challenge = $this->challenges->consume('passkey_authenticate', $data['challenge']);
        if (! $challenge) {
            throw ValidationException::withMessages(['challenge' => 'Desafio de passkey inválido ou expirado.']);
        }

        $credential = IdentityCredential::query()
            ->with('user')
            ->where('type', 'passkey')
            ->where('credential_id', $data['credential_id'])
            ->first();

        if (! $credential || ! $credential->user) {
            throw ValidationException::withMessages(['credential_id' => 'Passkey não reconhecida.']);
        }

        $clientDataRaw = $this->decodeRequired($data['client_data_json'], 'client_data_json');
        $clientData = json_decode($clientDataRaw, true);
        $this->validateClientData($clientData, 'webauthn.get', $data['challenge']);

        $authenticatorData = $this->decodeRequired($data['authenticator_data'], 'authenticator_data');
        $this->validateAuthenticatorData($authenticatorData);
        $signature = $this->decodeRequired($data['signature'], 'signature');

        $signedData = $authenticatorData . hash('sha256', $clientDataRaw, true);
        $pem = $this->derToPem(base64_decode($credential->public_key, true) ?: '');
        $verified = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw ValidationException::withMessages(['signature' => 'Assinatura da passkey inválida.']);
        }

        $newCount = $this->signCount($authenticatorData);
        if ($credential->sign_count > 0 && $newCount > 0 && $newCount <= $credential->sign_count) {
            throw ValidationException::withMessages(['credential_id' => 'A passkey apresentou um contador de segurança inválido.']);
        }

        $credential->forceFill([
            'sign_count' => max((int) $credential->sign_count, $newCount),
            'last_used_at' => now(),
        ])->save();

        return $credential->user;
    }

    public function delete(User $user, IdentityCredential $credential): void
    {
        abort_unless((int) $credential->user_id === (int) $user->id && $credential->type === 'passkey', 404);
        $credential->delete();
    }

    private function validateClientData(mixed $clientData, string $expectedType, string $expectedChallenge): void
    {
        if (! is_array($clientData)
            || ($clientData['type'] ?? null) !== $expectedType
            || ! is_string($clientData['challenge'] ?? null)
            || ! hash_equals($expectedChallenge, $clientData['challenge'])) {
            throw ValidationException::withMessages(['client_data_json' => 'Dados WebAuthn inválidos.']);
        }

        $origin = $clientData['origin'] ?? null;
        $allowed = array_map(fn ($value) => rtrim((string) $value, '/'), config('identity.passkeys.origins', []));
        if (! is_string($origin) || ! in_array(rtrim($origin, '/'), $allowed, true)) {
            throw ValidationException::withMessages(['client_data_json' => 'Origem WebAuthn não autorizada.']);
        }
    }

    private function validateAuthenticatorData(string $data): void
    {
        if (strlen($data) < 37) {
            throw ValidationException::withMessages(['authenticator_data' => 'Dados do autenticador incompletos.']);
        }

        $rpHash = substr($data, 0, 32);
        if (! hash_equals(hash('sha256', $this->rpId(), true), $rpHash)) {
            throw ValidationException::withMessages(['authenticator_data' => 'Passkey destinada a outro domínio.']);
        }

        $flags = ord($data[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw ValidationException::withMessages(['authenticator_data' => 'A passkey exige presença e verificação do usuário.']);
        }
    }

    private function signCount(string $authenticatorData): int
    {
        $unpacked = unpack('Ncount', substr($authenticatorData, 33, 4));
        return max((int) ($unpacked['count'] ?? 0), 0);
    }

    private function decodeRequired(string $value, string $field): string
    {
        $decoded = $this->challenges->decodeBase64Url($value);
        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages([$field => 'Valor WebAuthn inválido.']);
        }

        return $decoded;
    }

    private function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function rpId(): string
    {
        return strtolower(trim((string) config('identity.passkeys.rp_id', 'petertecnet.com.br')));
    }
}
