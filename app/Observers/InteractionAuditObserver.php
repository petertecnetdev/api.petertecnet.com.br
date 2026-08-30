<?php

namespace App\Observers;

use App\Models\Interaction;
use App\Services\ApplicationContextService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InteractionAuditObserver
{
    private const HIDDEN = ['password', 'remember_token', 'token_version', 'google_id', 'cpf', 'document', 'card_number', 'cvv', 'secret'];

    public function created(Model $model): void { $this->record($model, 'create', [], $model->getAttributes()); }
    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if (! $changes) return;
        $before = [];
        foreach (array_keys($changes) as $field) $before[$field] = $model->getOriginal($field);
        $this->record($model, 'update', $before, $changes);
    }
    public function deleted(Model $model): void { $this->record($model, 'delete', $model->getOriginal(), []); }

    private function record(Model $model, string $action, array $before, array $after): void
    {
        try { $user = Auth::guard('api')->user(); } catch (\Throwable) { $user = null; }
        $request = request();
        $sourceApplication = app(ApplicationContextService::class)->resolveSource($request);
        $targetApplicationId = $model->app_id ?? $model->application_id ?? null;
        $before = $this->sanitize($before);
        $after = $this->sanitize($after);
        $label = $model->name ?? $model->title ?? $model->order_number ?? $model->email ?? class_basename($model).' #'.$model->getKey();
        $verb = ['create' => 'Criou', 'update' => 'Atualizou', 'delete' => 'Excluiu'][$action];

        Interaction::create([
            'user_id' => $user?->id,
            'app_id' => $sourceApplication?->id ?: $targetApplicationId,
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'interaction_type' => $action,
            'outcome' => 'success',
            'severity' => in_array($action, ['delete'], true) || class_basename($model) === 'Profile' ? 'attention' : 'normal',
            'environment' => app()->environment(),
            'request_id' => $request?->attributes->get('request_id'),
            'correlation_id' => $request?->header('X-Correlation-ID') ?: $request?->attributes->get('request_id'),
            'parent_interaction_id' => is_numeric($request?->header('X-Parent-Interaction-ID')) ? (int) $request->header('X-Parent-Interaction-ID') : null,
            'name' => "{$verb} {$label}",
            'content' => array_filter([
                'changes' => ['before' => $before, 'after' => $after],
                'source_app_id' => $sourceApplication?->id,
                'source_app_slug' => $sourceApplication?->slug,
                'target_app_id' => $targetApplicationId,
                'application_context' => $sourceApplication ? 'source' : 'target_fallback',
                'entity_snapshot' => ['type' => class_basename($model), 'id' => $model->getKey(), 'name' => $label],
                'path' => $request?->path(),
                'frontend_page' => $request?->header('X-Frontend-Page') ?: $request?->header('Referer'),
                'origin' => $request?->header('Origin'),
                'referer' => $request?->header('Referer'),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'status' => 200,
            ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
        ]);
    }

    private function sanitize(array $values): array
    {
        foreach ($values as $field => $value) {
            if (in_array(strtolower((string) $field), self::HIDDEN, true)) $values[$field] = '[REDACTED]';
            elseif (is_object($value)) $values[$field] = method_exists($value, 'toISOString') ? $value->toISOString() : (string) $value;
        }
        return $values;
    }
}
