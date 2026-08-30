<?php

namespace App\Http\Middleware;

use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackApiInteraction
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'code', 'verification_code', 'recovery_code', 'secret',
        'client_secret', 'card_number', 'card', 'cvv', 'cvc', 'cpf', 'document',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        try { $user = Auth::guard('api')->user(); } catch (\Throwable) { $user = null; }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            try { $this->recordException($request, $user, $startedAt, $exception); }
            catch (\Throwable $trackingException) { $this->logTrackingFailure($request, $trackingException); }
            throw $exception;
        }

        try {
            if ($this->shouldTrack($request, $response)) $this->record($request, $response, $user, $startedAt);
        } catch (\Throwable $exception) {
            $this->logTrackingFailure($request, $exception);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        $path = $request->path();
        $routeName = (string) $request->route()?->getName();
        $status = $response->getStatusCode();

        if ($request->isMethod('OPTIONS') || str_starts_with($path, 'broadcasting/') || $path === 'api/interactions/batch') return false;
        if (str_starts_with($path, 'api/admin/')) return $status >= 400;
        if (in_array($routeName, ['auth.google', 'invite', 'invite.complete'], true) || str_starts_with($path, 'api/auth/')) return $status >= 400;
        if ($status >= 400 || ! $request->isMethod('GET')) return true;

        return preg_match('/\.(view|show|download)$/', $routeName) === 1;
    }

    private function record(Request $request, Response $response, $user, float $startedAt): void
    {
        $route = $request->route();
        $routeName = (string) $route?->getName();
        $status = $response->getStatusCode();

        $parameters = $this->redact($route?->parameters() ?? []);
        $input = $this->redact($request->all());
        $entity = $this->entitySnapshot($routeName, $parameters, $input);
        $type = $status >= 400 ? 'request_error' : $this->interactionType($request, $routeName);

        Interaction::create([
            'user_id' => $user?->id,
            'interaction_type' => $type,
            'outcome' => $this->outcome($status),
            'severity' => $this->severity($request, $status),
            'environment' => app()->environment(),
            'entity_type' => $entity['type'] ?? $this->entityType($routeName),
            'entity_id' => $entity['id'] ?? $this->numericEntityId($parameters),
            'request_id' => $this->requestId($request),
            'correlation_id' => $this->correlationId($request),
            'parent_interaction_id' => $this->parentInteractionId($request),
            'name' => $this->description($type, $entity, $routeName, $status),
            'content' => $this->content($request, $response, $user, $startedAt, $parameters, $input, $entity),
        ]);
    }

    private function recordException(Request $request, $user, float $startedAt, \Throwable $exception): void
    {
        if ($request->isMethod('OPTIONS') || str_starts_with($request->path(), 'broadcasting/') || $request->path() === 'api/interactions/batch') return;

        $route = $request->route();
        $routeName = (string) $route?->getName();
        $parameters = $this->redact($route?->parameters() ?? []);
        $input = $this->redact($request->all());
        $entity = $this->entitySnapshot($routeName, $parameters, $input);
        $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

        Interaction::create([
            'user_id' => $user?->id,
            'interaction_type' => 'request_error',
            'outcome' => $this->outcome($status),
            'severity' => $this->severity($request, $status),
            'environment' => app()->environment(),
            'entity_type' => $entity['type'] ?? $this->entityType($routeName),
            'entity_id' => $entity['id'] ?? $this->numericEntityId($parameters),
            'request_id' => $this->requestId($request),
            'correlation_id' => $this->correlationId($request),
            'parent_interaction_id' => $this->parentInteractionId($request),
            'name' => $this->description('request_error', $entity, $routeName, $status),
            'content' => array_merge($this->baseContext($request, $user, $startedAt, $parameters, $input, $entity), [
                'status' => $status,
                'error' => mb_substr($exception->getMessage() ?: 'Erro interno da API', 0, 500),
                'error_code' => class_basename($exception),
                'exception' => get_class($exception),
            ]),
        ]);
    }

    private function content(Request $request, Response $response, $user, float $startedAt, array $parameters, array $input, array $entity): array
    {
        $status = $response->getStatusCode();
        return array_filter(array_merge($this->baseContext($request, $user, $startedAt, $parameters, $input, $entity), [
            'status' => $status,
            'response_message' => $this->responseMessage($response),
            'error' => $status >= 400 ? $this->responseMessage($response) : null,
            'error_code' => $status >= 400 ? 'HTTP_'.$status : null,
        ]), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function baseContext(Request $request, $user, float $startedAt, array $parameters, array $input, array $entity): array
    {
        $agent = (string) $request->userAgent();
        return array_filter([
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'frontend_page' => $request->headers->get('X-Frontend-Page') ?: $request->headers->get('Referer'),
            'origin' => $request->headers->get('Origin'),
            'referer' => $request->headers->get('Referer'),
            'parameters' => $parameters ?: null,
            'query' => $this->redact($request->query()),
            'input' => $input ?: null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'device' => $this->device($agent),
            'browser' => $this->browser($agent),
            'operating_system' => $this->operatingSystem($agent),
            'user_agent' => $agent,
            'ip' => $request->ip(),
            'location' => array_filter(['city' => $input['city'] ?? null, 'uf' => $input['uf'] ?? null]),
            'user_snapshot' => $this->userSnapshot($user),
            'entity_snapshot' => $entity ?: null,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function userSnapshot($user): ?array
    {
        if (! $user) return null;
        $user->loadMissing(['profile:id,name', 'applications:id,name,slug', 'establishments:id,name,app_id']);
        return [
            'id' => $user->id,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->user_name,
            'email' => $user->email,
            'profile' => $user->profile?->name,
            'applications' => $user->applications->map->only(['id', 'name', 'slug'])->values()->all(),
            'establishments' => $user->establishments->map->only(['id', 'name', 'app_id'])->values()->all(),
        ];
    }

    private function entitySnapshot(string $routeName, array $parameters, array $input): array
    {
        $type = $this->entityType($routeName);
        $value = $parameters['id'] ?? $parameters['slug'] ?? $parameters['identifier'] ?? $parameters['user'] ?? $input['entity_id'] ?? $input['item_id'] ?? null;
        if (! $type || $value === null || is_object($value)) return $type ? ['type' => $type] : [];

        $model = match ($type) {
            'Establishment' => Establishment::class, 'Item' => Item::class, 'Order' => Order::class, 'User' => User::class, default => null,
        };
        if (! $model) return ['type' => $type, 'id' => is_numeric($value) ? (int) $value : null, 'identifier' => (string) $value];

        $record = is_numeric($value) ? $model::find($value) : $model::where('slug', $value)->first();
        if (! $record) return ['type' => $type, 'id' => is_numeric($value) ? (int) $value : null, 'identifier' => (string) $value];

        $snapshot = ['type' => $type, 'id' => $record->id, 'name' => $record->name ?? $record->title ?? $record->order_number ?? $record->email ?? null];
        if ($record instanceof Item) {
            $record->loadMissing('establishment.user');
            $snapshot['establishment'] = $record->establishment ? ['id' => $record->establishment->id, 'name' => $record->establishment->name] : null;
            $snapshot['owner'] = $record->establishment?->user ? ['id' => $record->establishment->user->id, 'name' => trim(($record->establishment->user->first_name ?? '').' '.($record->establishment->user->last_name ?? '')), 'email' => $record->establishment->user->email] : null;
        } elseif ($record instanceof Establishment) {
            $record->loadMissing('user');
            $snapshot['establishment'] = ['id' => $record->id, 'name' => $record->name];
            $snapshot['owner'] = $record->user ? ['id' => $record->user->id, 'name' => trim(($record->user->first_name ?? '').' '.($record->user->last_name ?? '')), 'email' => $record->user->email] : null;
        }
        return array_filter($snapshot, fn ($value) => $value !== null && $value !== '');
    }

    private function description(string $type, array $entity, string $routeName, int $status): string
    {
        $label = $entity['name'] ?? $entity['identifier'] ?? $routeName ?: 'recurso';
        if ($status >= 400) return "Tentou acessar {$label}, mas a operação foi recusada";
        $verb = match ($type) { 'view' => 'Visualizou', 'create' => 'Criou', 'update' => 'Atualizou', 'delete' => 'Excluiu', default => 'Executou ação em' };
        return "{$verb} {$label}";
    }

    private function interactionType(Request $request, string $routeName): string
    {
        if ($request->isMethod('GET')) return 'view';
        if ($request->isMethod('DELETE')) return 'delete';
        if ($request->isMethod('PUT') || $request->isMethod('PATCH')) return 'update';
        if (str_ends_with($routeName, '.store')) return 'create';
        return 'action';
    }

    private function outcome(int $status): string
    {
        return $status < 400 ? 'success' : (in_array($status, [401, 403, 404, 409, 422, 429], true) ? 'refused' : 'error');
    }

    private function severity(Request $request, int $status): string
    {
        $route = strtolower((string) $request->route()?->getName().' '.$request->path());
        if ($status >= 500) return 'critical';
        if (in_array($status, [401, 403, 429], true)) return 'suspicious';
        if ($status >= 400 || preg_match('/password|profile|permission|access|destroy|delete/', $route)) return 'attention';
        return 'normal';
    }

    private function entityType(string $routeName): ?string
    {
        $prefix = explode('.', $routeName)[0] ?? '';
        return match ($prefix) {
            'establishment' => 'Establishment', 'item' => 'Item', 'order' => 'Order', 'user' => 'User',
            'employer' => 'Employer', 'event' => 'Event', 'production' => 'Production', 'ticket' => 'Ticket',
            'news' => 'News', default => $prefix ? ucfirst(str_replace(['-', '_'], ' ', $prefix)) : null,
        };
    }

    private function numericEntityId(array $parameters): ?int
    {
        foreach (['id', 'user', 'order', 'item', 'establishment', 'entity_id'] as $key) if (isset($parameters[$key]) && is_numeric($parameters[$key])) return (int) $parameters[$key];
        return null;
    }

    private function responseMessage(Response $response): ?string
    {
        $decoded = json_decode((string) $response->getContent(), true);
        $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? null) : null;
        return is_scalar($message) ? mb_substr((string) $message, 0, 500) : ($response->getStatusCode() >= 400 ? 'Requisição recusada pela API' : null);
    }

    private function requestId(Request $request): string { return (string) ($request->attributes->get('request_id') ?: $request->header('X-Request-ID')); }
    private function correlationId(Request $request): string { return (string) ($request->header('X-Correlation-ID') ?: $this->requestId($request)); }
    private function parentInteractionId(Request $request): ?int { $value = $request->header('X-Parent-Interaction-ID'); return is_numeric($value) ? (int) $value : null; }
    private function device(string $agent): string { return preg_match('/tablet|ipad/i', $agent) ? 'tablet' : (preg_match('/mobile|android|iphone/i', $agent) ? 'mobile' : 'desktop'); }
    private function browser(string $agent): string { foreach (['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) if (str_contains($agent, $needle)) return $name; return 'Outro'; }
    private function operatingSystem(string $agent): string { foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) if (str_contains($agent, $needle)) return $name; return 'Outro'; }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) $data[$key] = '[REDACTED]';
            elseif (is_array($value)) $data[$key] = $this->redact($value);
            elseif (is_object($value)) $data[$key] = '[OBJECT]';
        }
        return $data;
    }

    private function logTrackingFailure(Request $request, \Throwable $exception): void
    {
        Log::warning('Failed to track API interaction.', ['route' => $request->route()?->getName(), 'message' => $exception->getMessage()]);
    }
}
