<?php

namespace App\Support\Idempotency;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IdempotencyService
{
    public function claim(
        ?string $key,
        string $applicationKey,
        string $actorKey,
        string $routeSignature,
        array $payload,
    ): array {
        $key = trim((string) $key);
        if ($key === '') {
            return ['state' => 'disabled'];
        }

        if (mb_strlen($key) < 8 || mb_strlen($key) > 80) {
            throw new InvalidArgumentException('A chave de idempotência deve ter entre 8 e 80 caracteres.');
        }

        $fingerprint = hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        $now = now();

        $inserted = DB::table('idempotent_requests')->insertOrIgnore([
            'application_key' => $applicationKey,
            'actor_key' => $actorKey,
            'idempotency_key' => $key,
            'route_signature' => $routeSignature,
            'request_fingerprint' => $fingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = DB::table('idempotent_requests')
            ->where('application_key', $applicationKey)
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $key)
            ->first();

        if (! $record) {
            throw new \RuntimeException('Não foi possível registrar a operação idempotente.');
        }

        if (! hash_equals((string) $record->request_fingerprint, $fingerprint)
            || ! hash_equals((string) $record->route_signature, $routeSignature)) {
            return ['state' => 'conflict', 'id' => (int) $record->id];
        }

        if ($record->completed_at !== null) {
            return ['state' => 'replay', 'id' => (int) $record->id, 'record' => $record];
        }

        if (! $inserted) {
            return ['state' => 'processing', 'id' => (int) $record->id];
        }

        return ['state' => 'claimed', 'id' => (int) $record->id];
    }

    public function complete(int $id, JsonResponse $response): void
    {
        DB::table('idempotent_requests')
            ->where('id', $id)
            ->whereNull('completed_at')
            ->update([
                'response_status' => $response->getStatusCode(),
                'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function release(int $id): void
    {
        DB::table('idempotent_requests')
            ->where('id', $id)
            ->whereNull('completed_at')
            ->delete();
    }

    public function replay(object $record): JsonResponse
    {
        $decoded = json_decode((string) $record->response_body, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Resposta idempotente armazenada inválida.');
        }

        $response = response()->json($decoded, (int) ($record->response_status ?: 200));
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
