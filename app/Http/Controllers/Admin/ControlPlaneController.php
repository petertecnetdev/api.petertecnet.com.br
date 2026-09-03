<?php

namespace App\Http\Controllers\Admin;

use App\Events\EcosystemUpdated;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ControlPlaneController extends Controller
{
    private const ENTITY_MAP = [
        'user' => User::class,
        'establishment' => Establishment::class,
        'application' => Application::class,
        'item' => Item::class,
        'order' => Order::class,
        'payment' => EcosystemPayment::class,
        'event' => Event::class,
        'service-record' => ServiceRecord::class,
    ];

    public function realtimeConfig(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $app = config('reverb.apps.apps.0', []);
        $options = $app['options'] ?? [];

        return response()->json([
            'driver' => 'reverb',
            'key' => $app['key'] ?? null,
            'host' => $options['host'] ?? config('reverb.servers.reverb.hostname'),
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $options['scheme'] ?? 'https',
            'auth_endpoint' => url('/broadcasting/auth'),
            'channels' => [
                ['name' => 'ecosystem.admin', 'event' => 'ecosystem.updated'],
                ['name' => 'user.' . $request->user()->id, 'event' => 'app.notification.created'],
            ],
        ]);
    }

    public function entity(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeAccess($request);
        $class = self::ENTITY_MAP[$type] ?? null;
        abort_unless($class, 404, 'Tipo de entidade não suportado.');

        /** @var Model $entity */
        $entity = $class::query()->findOrFail($id);
        $modelName = class_basename($class);

        $audit = EcosystemAuditLog::query()
            ->with('user:id,first_name,last_name,email')
            ->where('entity_id', $id)
            ->where(function ($query) use ($class, $type, $modelName) {
                $query->where('entity_type', $class)
                    ->orWhere('entity_type', $type)
                    ->orWhere('entity_type', $modelName)
                    ->orWhere('entity_type', 'like', '%\\' . $modelName);
            })
            ->latest('id')->limit(50)->get();

        $activity = Interaction::query()
            ->with(['user:id,first_name,last_name,email', 'app:id,name,slug'])
            ->where(function ($query) use ($type, $id, $modelName) {
                $query->where(function ($entityQuery) use ($type, $id, $modelName) {
                    $entityQuery->where('entity_id', $id)
                        ->where(function ($typeQuery) use ($type, $modelName) {
                            $typeQuery->where('entity_type', 'like', '%' . $type . '%')
                                ->orWhere('entity_type', 'like', '%' . $modelName . '%');
                        });
                });
                if ($type === 'user') $query->orWhere('user_id', $id);
                if ($type === 'application') $query->orWhere('app_id', $id);
            })
            ->latest('id')->limit(50)->get();

        return response()->json([
            'type' => $type,
            'entity' => $entity,
            'related_counts' => $this->relatedCounts($type, $id),
            'activity' => $activity,
            'audit' => $audit,
            'diffs' => $audit->map(fn (EcosystemAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'before' => $log->before,
                'after' => $log->after,
                'changed_fields' => $this->changedFields($log->before, $log->after),
                'user' => $log->user,
                'created_at' => $log->created_at,
            ]),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $limit = max(10, min(100, (int) $request->query('limit', 40)));

        $personal = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')->limit($limit)->get();

        $critical = Interaction::query()
            ->with('app:id,name,slug')
            ->where(function ($query) {
                $query->whereIn('severity', ['critical', 'suspicious'])
                    ->orWhere('outcome', 'error');
            })
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')->limit(20)->get()
            ->map(fn (Interaction $item) => [
                'id' => 'interaction-' . $item->id,
                'source' => 'operations',
                'type' => $item->severity ?: 'error',
                'title' => $item->app?->name ? 'Evento em ' . $item->app->name : 'Evento operacional',
                'message' => $item->name ?: $item->interaction_type,
                'reference_type' => $item->entity_type,
                'reference_id' => $item->entity_id,
                'read_at' => null,
                'created_at' => $item->created_at,
            ]);

        return response()->json([
            'unread_count' => $personal->whereNull('read_at')->count() + $critical->count(),
            'notifications' => $personal,
            'operational' => $critical,
        ]);
    }

    public function markNotificationRead(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        if (! $notification->read_at) $notification->forceFill(['read_at' => now()])->save();
        return response()->json(['notification' => $notification->fresh()]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function timeline(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $limit = max(20, min(200, (int) $request->query('limit', 80)));

        $activity = Interaction::query()->with(['user:id,first_name,last_name,email', 'app:id,name,slug'])
            ->latest('id')->limit($limit)->get()->map(fn (Interaction $item) => [
                'source' => 'interaction', 'id' => $item->id, 'at' => $item->created_at,
                'type' => $item->interaction_type, 'severity' => $item->severity,
                'title' => $item->name ?: $item->interaction_type,
                'entity_type' => $item->entity_type, 'entity_id' => $item->entity_id,
                'user' => $item->user, 'application' => $item->app,
                'outcome' => $item->outcome, 'http_status' => $item->http_status,
            ]);

        $audit = EcosystemAuditLog::query()->with('user:id,first_name,last_name,email')
            ->latest('id')->limit($limit)->get()->map(fn (EcosystemAuditLog $item) => [
                'source' => 'audit', 'id' => $item->id, 'at' => $item->created_at,
                'type' => $item->action, 'severity' => 'normal', 'title' => $item->action,
                'entity_type' => $item->entity_type, 'entity_id' => $item->entity_id,
                'user' => $item->user, 'before' => $item->before, 'after' => $item->after,
                'changed_fields' => $this->changedFields($item->before, $item->after),
            ]);

        return response()->json([
            'timeline' => $activity->concat($audit)->sortByDesc(fn ($row) => (string) $row['at'])->take($limit)->values(),
        ]);
    }

    public function insights(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $now = now();
        $currentStart = $now->copy()->subDays(30);
        $previousStart = $now->copy()->subDays(60);

        $metrics = [
            'users' => $this->periodMetric(User::query(), $currentStart, $previousStart),
            'establishments' => $this->periodMetric(Establishment::query(), $currentStart, $previousStart),
            'interactions' => $this->periodMetric(Interaction::query(), $currentStart, $previousStart),
            'orders' => $this->periodMetric(Order::query(), $currentStart, $previousStart),
            'payments' => $this->periodMetric(EcosystemPayment::query(), $currentStart, $previousStart),
        ];

        $lastHourErrors = Interaction::query()->where('created_at', '>=', $now->copy()->subHour())->where('outcome', 'error')->count();
        $previousHourErrors = Interaction::query()->whereBetween('created_at', [$now->copy()->subHours(2), $now->copy()->subHour()])->where('outcome', 'error')->count();
        $ratio = $previousHourErrors > 0 ? $lastHourErrors / $previousHourErrors : ($lastHourErrors > 0 ? $lastHourErrors : 0);

        $anomalies = collect();
        if ($lastHourErrors >= 5 && $ratio >= 2) {
            $anomalies->push([
                'severity' => $ratio >= 4 ? 'critical' : 'warning',
                'type' => 'error_spike',
                'title' => 'Aumento incomum de erros',
                'message' => sprintf('%d erros na última hora, %.1fx o período anterior.', $lastHourErrors, $ratio),
                'current' => $lastHourErrors,
                'baseline' => $previousHourErrors,
            ]);
        }

        $staleApps = Application::query()->where('is_active', true)->get(['id', 'name', 'slug'])->filter(function (Application $app) use ($now) {
            $last = Interaction::query()->where('app_id', $app->id)->latest('id')->value('created_at');
            return $last && $last < $now->copy()->subMinutes(30);
        })->values()->map(fn (Application $app) => [
            'severity' => 'warning', 'type' => 'telemetry_stale', 'title' => 'Telemetria sem atividade recente',
            'message' => $app->name . ' está sem interações há mais de 30 minutos.',
            'entity_type' => 'application', 'entity_id' => $app->id,
        ]);

        return response()->json(['metrics' => $metrics, 'anomalies' => $anomalies->concat($staleApps)->values()]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['user', 'establishment', 'item', 'application'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['required', 'accepted'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
        ]);

        $allowed = [
            'user' => ['set_access'],
            'establishment' => ['approve', 'unapprove', 'publish', 'unpublish'],
            'item' => ['activate', 'deactivate'],
            'application' => ['activate', 'deactivate'],
        ];
        abort_unless(in_array($data['action'], $allowed[$data['entity_type']], true), 422, 'Ação em lote não suportada para este tipo de entidade.');
        if ($data['action'] === 'set_access') abort_unless(! empty($data['application_id']) && ! empty($data['status']), 422, 'Informe aplicação e status do acesso.');

        $class = self::ENTITY_MAP[$data['entity_type']];
        $updated = 0;

        DB::transaction(function () use ($request, $data, $class, &$updated) {
            foreach ($class::query()->whereKey($data['ids'])->get() as $entity) {
                $before = $entity->toArray();
                $this->applyBulkAction($entity, $data);
                $after = $entity->fresh()?->toArray() ?? $entity->toArray();
                EcosystemAuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'bulk.' . $data['action'],
                    'entity_type' => get_class($entity),
                    'entity_id' => $entity->getKey(),
                    'before' => $before,
                    'after' => array_merge($after, ['_reason' => $data['reason']]),
                    'ip' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                ]);
                $updated++;
            }
        });

        event(new EcosystemUpdated([$this->moduleForType($data['entity_type']), 'activity', 'audit', 'dashboard'], 'bulk.' . $data['action']));

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    private function applyBulkAction(Model $entity, array $data): void
    {
        if ($data['entity_type'] === 'user') {
            $existing = $entity->applications()->whereKey($data['application_id'])->first()?->pivot;
            $entity->applications()->syncWithoutDetaching([
                $data['application_id'] => [
                    'status' => $data['status'],
                    'role' => $existing?->role ?: 'member',
                    'metadata' => $existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existing?->joined_at ?: now(),
                ],
            ]);
            return;
        }

        $changes = match ($data['entity_type']) {
            'establishment' => match ($data['action']) {
                'approve' => ['is_approved' => true], 'unapprove' => ['is_approved' => false],
                'publish' => ['is_published' => true], 'unpublish' => ['is_published' => false], default => [],
            },
            'item' => ['status' => $data['action'] === 'activate'],
            'application' => ['is_active' => $data['action'] === 'activate'],
            default => [],
        };
        $entity->forceFill($changes)->save();
    }

    private function periodMetric($query, $currentStart, $previousStart): array
    {
        $current = (clone $query)->where('created_at', '>=', $currentStart)->count();
        $previous = (clone $query)->whereBetween('created_at', [$previousStart, $currentStart])->count();
        $change = $previous > 0 ? (($current - $previous) / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);
        return ['current' => $current, 'previous' => $previous, 'change_percent' => round($change, 1)];
    }

    private function relatedCounts(string $type, int $id): array
    {
        $definitions = match ($type) {
            'user' => [['establishments', 'user_id'], ['interactions', 'user_id'], ['application_user', 'user_id'], ['orders', 'user_id']],
            'establishment' => [['items', 'entity_id'], ['orders', 'establishment_id'], ['service_records', 'establishment_id'], ['events', 'establishment_id']],
            'application' => [['establishments', 'app_id'], ['items', 'app_id'], ['interactions', 'app_id'], ['application_user', 'application_id']],
            'item' => [['order_items', 'item_id']],
            'order' => [['order_items', 'order_id'], ['ecosystem_payments', 'order_id']],
            'event' => [['event_passes', 'event_id']],
            'service-record' => [],
            'payment' => [],
            default => [],
        };

        return collect($definitions)->mapWithKeys(function ($definition) use ($id) {
            [$table, $column] = $definition;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return [];
            return [$table => DB::table($table)->where($column, $id)->count()];
        })->all();
    }

    private function changedFields(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        return collect(array_unique(array_merge(array_keys($before), array_keys($after))))
            ->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->map(fn ($key) => ['field' => $key, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null])
            ->values()->all();
    }

    private function moduleForType(string $type): string
    {
        return match ($type) {
            'user' => 'users', 'establishment' => 'establishments', 'item' => 'items', 'application' => 'applications', default => 'dashboard',
        };
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        ), 403, 'Usuário sem permissão para administrar o ecossistema Peter Tecnet.');
    }
}
