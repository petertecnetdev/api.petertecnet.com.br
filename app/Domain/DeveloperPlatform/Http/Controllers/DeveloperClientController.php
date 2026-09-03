<?php

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Services\DeveloperCredentialService;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeveloperClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = ApiClient::query()
            ->with(['keys' => fn ($query) => $query->latest()])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (ApiClient $client) => $this->serialize($client));

        return ApiResponse::data($clients);
    }

    public function show(Request $request, ApiClient $client): JsonResponse
    {
        $client = $this->owned($request, $client);
        return ApiResponse::data($this->serialize($client->load('keys')));
    }

    public function store(Request $request, DeveloperCredentialService $credentials): JsonResponse
    {
        $availableScopes = array_keys((array) config('developer.scopes', []));
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::in(['production', 'sandbox'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($availableScopes)],
            'allowed_origins' => ['sometimes', 'array', 'max:20'],
            'allowed_origins.*' => ['url:http,https', 'max:255'],
            'key_name' => ['sometimes', 'string', 'max:80'],
            'key_expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $result = DB::transaction(function () use ($request, $validated, $credentials) {
            $client = ApiClient::create([
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'client_id' => $credentials->issueClientId(),
                'environment' => $validated['environment'],
                'status' => 'active',
                'scopes' => array_values(array_unique($validated['scopes'])),
                'allowed_origins' => $this->normalizeOrigins($validated['allowed_origins'] ?? []),
                'rate_limit_per_minute' => (int) config('developer.default_rate_limit_per_minute', 60),
            ]);

            $issued = $credentials->issueKey(
                $client,
                $validated['key_name'] ?? 'Default key',
                $validated['key_expires_in_days'] ?? null
            );

            return [$client, $issued];
        });

        [$client, $issued] = $result;

        return ApiResponse::data([
            'client' => $this->serialize($client->load('keys')),
            'api_key' => $issued['plain_text_key'],
            'api_key_notice' => 'Copie agora. Por segurança, esta chave completa não será exibida novamente.',
        ], [], 201);
    }

    public function update(Request $request, ApiClient $client): JsonResponse
    {
        $client = $this->owned($request, $client);
        $availableScopes = array_keys((array) config('developer.scopes', []));
        $maxRate = (int) config('developer.max_rate_limit_per_minute', 600);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($availableScopes)],
            'allowed_origins' => ['sometimes', 'array', 'max:20'],
            'allowed_origins.*' => ['url:http,https', 'max:255'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:' . $maxRate],
        ]);

        if (array_key_exists('scopes', $validated)) {
            $validated['scopes'] = array_values(array_unique($validated['scopes']));
        }
        if (array_key_exists('allowed_origins', $validated)) {
            $validated['allowed_origins'] = $this->normalizeOrigins($validated['allowed_origins']);
        }

        $client->fill($validated)->save();

        return ApiResponse::data($this->serialize($client->fresh('keys')));
    }

    public function destroy(Request $request, ApiClient $client): JsonResponse
    {
        $client = $this->owned($request, $client);
        $client->forceFill(['status' => 'disabled'])->save();
        $client->keys()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return ApiResponse::data(['disabled' => true]);
    }

    public function rotateKey(Request $request, ApiClient $client, DeveloperCredentialService $credentials): JsonResponse
    {
        $client = $this->owned($request, $client);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'revoke_existing' => ['sometimes', 'boolean'],
        ]);

        $issued = DB::transaction(function () use ($client, $validated, $credentials) {
            if ($validated['revoke_existing'] ?? true) {
                $client->keys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }

            return $credentials->issueKey(
                $client,
                $validated['name'] ?? 'Rotated key',
                $validated['expires_in_days'] ?? null
            );
        });

        return ApiResponse::data([
            'key' => $this->serializeKey($issued['key']),
            'api_key' => $issued['plain_text_key'],
            'api_key_notice' => 'Copie agora. A chave completa não poderá ser recuperada depois.',
        ], [], 201);
    }

    public function revokeKey(Request $request, ApiClient $client, ApiKey $key, DeveloperCredentialService $credentials): JsonResponse
    {
        $client = $this->owned($request, $client);
        abort_unless($key->api_client_id === $client->id, 404);
        $credentials->revoke($key);

        return ApiResponse::data(['revoked' => true]);
    }

    public function scopes(): JsonResponse
    {
        return ApiResponse::data(config('developer.scopes', []));
    }

    private function owned(Request $request, ApiClient $client): ApiClient
    {
        abort_unless($client->user_id === $request->user()->id, 404);
        return $client;
    }

    private function normalizeOrigins(array $origins): array
    {
        return collect($origins)
            ->map(function (string $origin) {
                $parts = parse_url($origin);
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = strtolower((string) ($parts['host'] ?? ''));
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return $scheme && $host ? $scheme . '://' . $host . $port : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function serialize(ApiClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'client_id' => $client->client_id,
            'environment' => $client->environment,
            'status' => $client->status,
            'scopes' => $client->scopes ?? [],
            'allowed_origins' => $client->allowed_origins ?? [],
            'rate_limit_per_minute' => $client->rate_limit_per_minute,
            'last_used_at' => optional($client->last_used_at)?->toISOString(),
            'keys' => $client->relationLoaded('keys')
                ? $client->keys->map(fn (ApiKey $key) => $this->serializeKey($key))->values()
                : [],
            'created_at' => optional($client->created_at)?->toISOString(),
        ];
    }

    private function serializeKey(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->key_prefix,
            'last_used_at' => optional($key->last_used_at)?->toISOString(),
            'expires_at' => optional($key->expires_at)?->toISOString(),
            'revoked_at' => optional($key->revoked_at)?->toISOString(),
            'created_at' => optional($key->created_at)?->toISOString(),
        ];
    }
}
