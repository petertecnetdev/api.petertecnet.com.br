<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityCompatibilityController extends Controller
{
    public function __construct(
        private readonly IdentityAuthenticationController $authentication,
        private readonly IdentityFederatedController $federated,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        return $this->legacyTokenEnvelope($this->authentication->login($request));
    }

    public function google(Request $request): JsonResponse
    {
        return $this->legacyTokenEnvelope($this->federated->google($request));
    }

    private function legacyTokenEnvelope(JsonResponse $response): JsonResponse
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $payload = $response->getData(true);
        if (($payload['two_factor_required'] ?? false) || empty($payload['access_token'])) {
            return $response;
        }

        $payload['message'] ??= 'Login realizado com sucesso!';
        $payload['token'] = [
            'access_token' => $payload['access_token'],
            'token_type' => $payload['token_type'] ?? 'bearer',
            'expires_in' => $payload['expires_in'] ?? null,
            'user' => $payload['user'] ?? null,
        ];

        $response->setData($payload);
        return $response;
    }
}
