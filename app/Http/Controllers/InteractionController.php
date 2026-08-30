<?php

namespace App\Http\Controllers;

use App\Events\EcosystemUpdated;
use App\Models\Interaction;
use App\Services\ApplicationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InteractionController extends Controller
{
    private const TYPES = [
        'session_start', 'session_end', 'navigation', 'click', 'form_submit',
        'field_change', 'search', 'filter', 'scroll', 'frontend_error',
    ];

    private const SENSITIVE = [
        'password', 'token', 'authorization', 'cookie', 'secret', 'code', 'cpf',
        'document', 'card', 'card_number', 'cvv', 'cvc', 'value',
    ];

    public function storeBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.id' => ['required', 'string', 'max:100'],
            'events.*.type' => ['required', Rule::in(self::TYPES)],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.page' => ['nullable', 'string', 'max:1000'],
            'events.*.label' => ['nullable', 'string', 'max:200'],
            'events.*.target' => ['nullable', 'string', 'max:200'],
            'events.*.metadata' => ['nullable', 'array'],
        ]);

        try { $user = Auth::guard('api')->user(); } catch (\Throwable) { $user = null; }
        $application = app(ApplicationContextService::class)->resolve($request);
        $sessionKey = substr(hash('sha256', (string) $request->ip().'|'.(string) $request->userAgent()), 0, 40);

        foreach ($data['events'] as $event) {
            $metadata = $this->sanitize($event['metadata'] ?? []);
            $type = $event['type'];
            Interaction::withoutEvents(fn () => Interaction::create([
                'user_id' => $user?->id,
                'app_id' => $application?->id,
                'interaction_type' => 'frontend_'.$type,
                'outcome' => $type === 'frontend_error' ? 'error' : 'success',
                'severity' => $type === 'frontend_error' ? 'attention' : 'normal',
                'environment' => app()->environment(),
                'request_id' => $event['id'],
                'correlation_id' => $data['session_id'],
                'session_key' => $sessionKey,
                'route' => $request->route()?->uri(),
                'method' => $request->method(),
                'name' => $this->description($type, $event),
                'content' => array_filter([
                    'frontend_event' => $type,
                    'frontend_page' => $event['page'] ?? null,
                    'target' => $event['target'] ?? null,
                    'label' => $event['label'] ?? null,
                    'client_timestamp' => $event['timestamp'],
                    'metadata' => $metadata,
                    'status' => $type === 'frontend_error' ? null : 200,
                    'origin' => $request->header('Origin'),
                    'referer' => $request->header('Referer'),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ]));
        }

        broadcast(new EcosystemUpdated(['dashboard', 'activity', 'audit'], 'frontend-telemetry'));

        return response()->json(['accepted' => count($data['events'])], 202);
    }

    private function description(string $type, array $event): string
    {
        $label = trim((string) ($event['label'] ?? ''));
        $page = trim((string) ($event['page'] ?? ''));
        return match ($type) {
            'session_start' => 'Iniciou uma sessão',
            'session_end' => 'Encerrou a sessão',
            'navigation' => 'Navegou para '.($page ?: 'outra página'),
            'click' => 'Clicou em '.($label ?: 'um elemento'),
            'form_submit' => 'Enviou '.($label ?: 'um formulário'),
            'field_change' => 'Alterou '.($label ?: 'um filtro ou opção'),
            'search' => 'Realizou uma busca',
            'filter' => 'Aplicou um filtro',
            'scroll' => 'Visualizou '.($label ?: 'parte da página'),
            'frontend_error' => 'Encontrou um erro na interface',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::SENSITIVE, true) || preg_match('/password|token|secret|cookie|card|cpf|document|code/', $normalized)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $values[$key] = mb_substr($value, 0, 500);
            }
        }
        return $values;
    }
}
