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

        $context = app(ApplicationContextService::class);
        $declared = $context->resolveStoredContext(['declared_app' => $request->header('X-Peter-App') ?: $request->header('X-App-Slug')]);
        $origin = $context->resolveStoredContext(['origin' => $request->header('Origin') ?: $request->header('Referer')]);
        abort_if($declared && $origin && $declared->id !== $origin->id, 422, 'A aplicação declarada não corresponde à origem da telemetria.');
        $application = $declared ?: $origin;
        abort_unless($application, 422, 'Não foi possível identificar a aplicação de origem da telemetria.');

        $sessionKey = substr(hash('sha256', $application->id.'|'.$data['session_id']), 0, 40);
        $eventIds = collect($data['events'])->pluck('id')->unique()->values();
        $existingIds = Interaction::query()
            ->where('app_id', $application->id)
            ->whereIn('request_id', $eventIds)
            ->pluck('request_id')
            ->flip();
        $accepted = 0;

        foreach ($data['events'] as $event) {
            if ($existingIds->has($event['id'])) continue;
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
                    'app_slug' => $application->slug,
                    'app_name' => $application->name,
                    'declared_app' => $request->header('X-Peter-App') ?: $request->header('X-App-Slug'),
                    'telemetry_schema' => $request->header('X-Telemetry-Schema') ?: '1',
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ]));
            $accepted++;
        }

        if ($accepted > 0) {
            broadcast(new EcosystemUpdated(['dashboard', 'activity', 'audit'], 'frontend-telemetry'));
        }

        return response()->json([
            'accepted' => $accepted,
            'duplicates' => count($data['events']) - $accepted,
            'application' => ['id' => $application->id, 'slug' => $application->slug],
        ], 202);
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
