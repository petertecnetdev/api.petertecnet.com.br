<?php

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Models\ApiKey;
use Illuminate\Support\Str;

class DeveloperCredentialService
{
    public function issueClientId(): string
    {
        do {
            $clientId = 'ptc_' . Str::lower(Str::random(24));
        } while (ApiClient::where('client_id', $clientId)->exists());

        return $clientId;
    }

    public function issueKey(ApiClient $client, string $name = 'Default key', ?int $expiresInDays = null): array
    {
        $prefix = $client->environment === 'sandbox' ? 'pt_test_' : 'pt_live_';
        $plainText = $prefix . Str::random(48);
        $keyPrefix = substr($plainText, 0, 16);

        $key = $client->keys()->create([
            'name' => $name,
            'key_prefix' => $keyPrefix,
            'key_hash' => hash('sha256', $plainText),
            'expires_at' => $expiresInDays ? now()->addDays($expiresInDays) : null,
        ]);

        return [
            'key' => $key,
            'plain_text_key' => $plainText,
        ];
    }

    public function revoke(ApiKey $key): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }
    }
}
