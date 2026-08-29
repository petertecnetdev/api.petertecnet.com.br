<?php

namespace App\Http\Middleware;

use App\Models\Interaction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackApiInteraction
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'authorization', 'code',
        'verification_code', 'recovery_code', 'secret', 'client_secret',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $user = Auth::guard('api')->user();
        $response = $next($request);

        try {
            if ($this->shouldTrack($request, $response)) {
                $this->record($request, $response, $user, $startedAt);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to track API interaction.', [
                'route' => $request->route()?->getName(),
                'message' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        $path = $request->path();
        $routeName = (string) $request->route()?->getName();
        $status = $response->getStatusCode();

        if ($request->isMethod('OPTIONS') || str_starts_with($path, 'broadcasting/')) {
            return false;
        }

        if (str_starts_with($path, 'api/admin/')) {
            return $status >= 400;
        }

        if (in_array($routeName, ['auth.google', 'invite', 'invite.complete'], true)
            || str_starts_with($path, 'api/auth/')) {
            return $status >= 400;
        }

        if ($status >= 400) {
            return true;
        }

        if (! $request->isMethod('GET')) {
            return true;
        }

        return preg_match('/\.(view|show|download)$/', $routeName) === 1;
    }

    private function record(Request $request, Response $response, $user, float $startedAt): void
    {
        $route = $request->route();
        $routeName = (string) $route?->getName();
        $status = $response->getStatusCode();

        $alreadyRecorded = Interaction::query()
            ->where('route', $route?->uri() ?: $request->path())
            ->when($user?->id, fn ($query, $id) => $query->where('user_id', $id))
            ->where('created_at', '>=', now()->subSeconds(5))
            ->exists();

        if ($alreadyRecorded && $status < 400) {
            return;
        }

        $input = $this->redact($request->all());
        $parameters = $this->redact($route?->parameters() ?? []);
        $responseMessage = $this->responseMessage($response);

        Interaction::create([
            'user_id' => $user?->id,
            'interaction_type' => $status >= 400 ? 'request_error' : $this->interactionType($request, $routeName),
            'entity_type' => $this->entityType($routeName),
            'entity_id' => $this->numericEntityId($parameters),
            'name' => $status >= 400 ? 'Tentativa com erro' : $this->displayName($routeName),
            'content' => array_filter([
                'route_name' => $routeName ?: null,
                'path' => $request->path(),
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'parameters' => $parameters ?: null,
                'input' => $input ?: null,
                'error' => $status >= 400 ? $responseMessage : null,
            ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
        ]);
    }

    private function interactionType(Request $request, string $routeName): string
    {
        if ($request->isMethod('GET')) return 'view';
        if ($request->isMethod('DELETE')) return 'delete';
        if ($request->isMethod('PUT') || $request->isMethod('PATCH')) return 'update';
        if (str_ends_with($routeName, '.store')) return 'create';
        return 'action';
    }

    private function entityType(string $routeName): ?string
    {
        $prefix = explode('.', $routeName)[0] ?? '';
        return match ($prefix) {
            'establishment' => 'Establishment',
            'item' => 'Item',
            'order' => 'Order',
            'user' => 'User',
            'employer' => 'Employer',
            'event' => 'Event',
            'production' => 'Production',
            'ticket' => 'Ticket',
            'news' => 'News',
            default => $prefix ? ucfirst(str_replace(['-', '_'], ' ', $prefix)) : null,
        };
    }

    private function numericEntityId(array $parameters): ?int
    {
        foreach (['id', 'user', 'order', 'item', 'establishment', 'entity_id'] as $key) {
            if (isset($parameters[$key]) && is_numeric($parameters[$key])) return (int) $parameters[$key];
        }
        return null;
    }

    private function displayName(string $routeName): string
    {
        return $routeName ? ucfirst(str_replace(['.', '_', '-'], ' ', $routeName)) : 'Ação na API';
    }

    private function responseMessage(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) return null;

        $decoded = json_decode((string) $response->getContent(), true);
        $message = is_array($decoded)
            ? ($decoded['message'] ?? $decoded['error'] ?? null)
            : null;

        return is_scalar($message) ? mb_substr((string) $message, 0, 500) : 'Requisição recusada pela API';
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $data[$key] = '[OBJECT]';
            }
        }

        return $data;
    }
}
