<?php

namespace App\Integrations\Identity;

use Google_Client;
use RuntimeException;

final class GoogleIdentityProvider
{
    public function __construct(private readonly ?Google_Client $client = null)
    {
    }

    /**
     * @return array{sub:string,email:string,email_verified:bool,given_name?:string}
     */
    public function verifyIdToken(string $token): array
    {
        $token = trim($token);
        $clientId = trim((string) config('services.google.client_id'));

        if ($token === '' || $clientId === '') {
            throw new RuntimeException('Google identity provider is not configured.');
        }

        $client = $this->client ?? new Google_Client([
            'client_id' => $clientId,
            'httpOptions' => [
                'timeout' => max(1, (int) config('services.google.timeout', 10)),
            ],
        ]);

        $payload = $client->verifyIdToken($token);

        if (! is_array($payload)
            || ! isset($payload['sub'], $payload['email'])
            || ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)
            || ! ($payload['email_verified'] ?? false)) {
            throw new RuntimeException('Google identity token is invalid.');
        }

        return [
            'sub' => (string) $payload['sub'],
            'email' => strtolower(trim((string) $payload['email'])),
            'email_verified' => true,
            'given_name' => isset($payload['given_name']) ? trim((string) $payload['given_name']) : null,
        ];
    }
}
