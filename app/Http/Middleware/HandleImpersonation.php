<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationAuditLog;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleImpersonation
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'code', 'verification_code', 'reset_password_code',
        'secret', 'client_secret', 'card', 'card_number', 'cvv', 'cvc', 'cpf', 'document',
    ];

    public function __construct(private readonly ImpersonationService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $this->sessionIdFromToken();
        if (! $sessionId) return $next($request);

        $session = $this->service->currentFromToken();
        if (! $session) {
            return response()->json([
                'message' => 'A sessão temporária de impersonação expirou ou foi encerrada.',
                'code' => 'impersonation_expired',
            ], 401);
        }

        $actor = $session->impersonator;
        $effective = $session->impersonatedUser;
        if (! $actor || ! $effective) {
            return response()->json(['message' => 'Sessão de impersonação inválida.', 'code' => 'impersonation_invalid'], 401);
        }

        $request->attributes->set('impersonation_session', $session);
        $request->attributes->set('actor_user', $actor);
        $request->attributes->set('effective_user', $effective);

        if ($this->isSensitiveOperation($request)) {
            $response = response()->json([
                'message' => 'Esta operação sensível é bloqueada enquanto um administrador atua como outro usuário.',
                'code' => 'impersonation_sensitive_operation_blocked',
            ], 403);
            $this->audit($request, $response, $session, $actor, $effective, true);
            return $response;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->auditException($request, $session, $actor, $effective, $exception);
            throw $exception;
        }

        if ($request->isMethod('POST') && strtolower(trim($request->path(), '/')) === 'api/auth/logout') {
            $session->finish($actor, 'logout_from_application');
        }

        $this->audit($request, $response, $session, $actor, $effective, false);
        return $response;
    }

    private function sessionIdFromToken(): ?int
    {
        try {
            if (! auth('api')->user()) return null;
            $value = auth('api')->payload()->get('impersonation_session_id');
            return is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSensitiveOperation(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) return false;

        $path = strtolower(trim($request->path(), '/'));

        $exact = [
            'api/auth/change-password',
            'api/auth/email-verify',
            'api/auth/refresh',
            'api/account/email/request-change',
            'api/account/email/confirm-change',
            'api/account/sso/handoff',
        ];
        if (in_array($path, $exact, true)) return true;

        if (str_starts_with($path, 'api/admin/')) return true;

        if ($request->isMethod('DELETE') && preg_match('#^api/user/\d+$#', $path)) return true;

        return preg_match('#(?:^|/)(password|permissions?|roles?|credentials?|api[-_]?keys?|secrets?|bank(?:ing)?|bank[-_]?accounts?|payouts?|withdrawals?|transfers?)(?:/|$)#i', $path) === 1;
    }

    private function audit(Request $request, Response $response, $session, $actor, $effective, bool $blocked): void
    {
        try {
            $route = $request->route();
            $parameters = $route?->parameters() ?? [];
            [$entityType, $entityId] = $this->entity($route?->getName(), $parameters);

            ImpersonationAuditLog::create([
                'impersonation_session_id' => $session->id,
                'actor_user_id' => $actor->id,
                'effective_user_id' => $effective->id,
                'application_id' => $session->application_id,
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $route?->getName(),
                'status_code' => $response->getStatusCode(),
                'action' => $blocked ? 'blocked_sensitive_operation' : $this->action($request),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'context' => [
                    'blocked' => $blocked,
                    'query' => $this->redact($request->query()),
                    'input' => $this->redact($request->all()),
                    'route_parameters' => $this->redact($parameters),
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function auditException(Request $request, $session, $actor, $effective, \Throwable $exception): void
    {
        try {
            $route = $request->route();
            [$entityType, $entityId] = $this->entity($route?->getName(), $route?->parameters() ?? []);
            ImpersonationAuditLog::create([
                'impersonation_session_id' => $session->id,
                'actor_user_id' => $actor->id,
                'effective_user_id' => $effective->id,
                'application_id' => $session->application_id,
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $route?->getName(),
                'status_code' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500,
                'action' => 'request_error',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'context' => ['exception' => get_class($exception), 'message' => mb_substr($exception->getMessage(), 0, 500)],
                'created_at' => now(),
            ]);
        } catch (\Throwable $trackingException) {
            report($trackingException);
        }
    }

    private function action(Request $request): string
    {
        return match ($request->method()) {
            'GET', 'HEAD' => 'view',
            'POST' => 'create_or_action',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($request->method()),
        };
    }

    private function entity(?string $routeName, array $parameters): array
    {
        $prefix = explode('.', (string) $routeName)[0] ?? null;
        $type = $prefix ? str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $prefix))) : null;
        $id = null;
        foreach (['id', 'user', 'item', 'event', 'ticket', 'establishment', 'order', 'employer'] as $key) {
            $value = $parameters[$key] ?? null;
            if (is_object($value) && isset($value->id)) $value = $value->id;
            if (is_numeric($value)) { $id = (int) $value; break; }
        }
        return [$type, $id];
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $data[$key] = method_exists($value, 'getKey') ? ['id' => $value->getKey()] : '[OBJECT]';
            }
        }
        return $data;
    }
}
