<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\OauthClient;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OauthTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'grant_type' => 'required|in:client_credentials',
            'client_id' => 'required|uuid',
            'client_secret' => 'required|string|max:255',
            'scope' => 'nullable|string|max:2000',
        ]);

        $client = OauthClient::query()->where('client_id', $data['client_id'])->with('project')->first();
        if (! $client || ! $client->valid() || ! hash_equals($client->secret_hash, hash('sha256', $data['client_secret']))) {
            return ApiResponse::error('INVALID_CLIENT', 'Credenciais OAuth inválidas.', 401, [], $request);
        }

        $requested = array_values(array_filter(preg_split('/\s+/', trim((string) ($data['scope'] ?? '')))));
        $allowed = $client->scopes ?: [];
        $scopes = $requested ?: $allowed;
        foreach ($scopes as $scope) {
            if (! in_array('*', $allowed, true) && ! in_array($scope, $allowed, true)) {
                return ApiResponse::error('INVALID_SCOPE', 'Um ou mais escopos solicitados não são permitidos.', 400, ['scope' => $scope], $request);
            }
            if (! $client->project->allows($scope)) {
                return ApiResponse::error('INVALID_SCOPE', 'O projeto não permite um ou mais escopos solicitados.', 400, ['scope' => $scope], $request);
            }
        }

        $raw = 'pt_oauth_' . Str::lower(Str::random(48));
        ApiCredential::create([
            'api_project_id' => $client->api_project_id,
            'name' => 'oauth:' . $client->client_id,
            'key_prefix' => substr($raw, 0, 16),
            'secret_hash' => hash('sha256', $raw),
            'scopes' => $scopes,
            'expires_at' => now()->addHour(),
        ]);
        $client->update(['last_used_at' => now()]);

        return response()->json([
            'access_token' => $raw,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $scopes),
        ]);
    }
}
