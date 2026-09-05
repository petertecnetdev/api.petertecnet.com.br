<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\Employer;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdminUserDetailService
{
    private const RESOURCE_LIMIT = 250;

    public function detail(User $user): array
    {
        $user->load([
            'profile:id,name,permissions',
            'applications:id,name,slug,logo,url,is_active',
            'establishments.app:id,name,slug,logo,url',
        ]);

        $applications = Application::query()
            ->get(['id', 'name', 'slug', 'logo', 'url', 'is_active'])
            ->keyBy('id');

        $recentActivity = $this->interactionQuery($user)
            ->latest('created_at')
            ->limit(500)
            ->get();

        $logins = $recentActivity->whereIn('interaction_type', ['login', 'login_google']);
        $resources = $this->resources($user, $applications);
        $usage = $this->applicationUsage($user, $applications);

        $ips = $recentActivity
            ->map(fn (Interaction $interaction) => data_get($interaction->content, 'ip'))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->map(fn ($count, $value) => ['value' => $value, 'count' => $count])
            ->values();

        $locations = $recentActivity
            ->map(function (Interaction $interaction) {
                $city = data_get($interaction->content, 'city');
                $uf = data_get($interaction->content, 'uf');
                return $city ? trim($city . ($uf ? "/{$uf}" : '')) : null;
            })
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->map(fn ($count, $value) => ['value' => $value, 'count' => $count])
            ->values();

        $devices = $recentActivity
            ->map(fn (Interaction $interaction) => $this->deviceLabel(data_get($interaction->content, 'user_agent')))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->map(fn ($count, $value) => ['value' => $value, 'count' => $count])
            ->values();

        $securityAlerts = [];
        $recentLoginIps = $logins->take(20)->map(fn (Interaction $interaction) => data_get($interaction->content, 'ip'))->filter();
        if ($recentLoginIps->unique()->count() >= 5) {
            $securityAlerts[] = ['level' => 'warning', 'message' => 'Múltiplos IPs diferentes foram usados nos logins recentes.'];
        }
        if ($logins->count() >= 2 && $logins->take(10)->groupBy(fn (Interaction $interaction) => $interaction->created_at?->format('Y-m-d H:i'))->max(fn ($group) => $group->count()) >= 3) {
            $securityAlerts[] = ['level' => 'warning', 'message' => 'Foram detectados vários logins em um intervalo curto.'];
        }
        if ($recentActivity->isEmpty()) {
            $securityAlerts[] = ['level' => 'info', 'message' => 'Ainda não há atividade registrada para este usuário.'];
        }

        $baseInteractions = Interaction::query()->where('user_id', $user->id);
        $summary = [
            'total_interactions' => (clone $baseInteractions)->count(),
            'interactions_7d' => (clone $baseInteractions)->where('created_at', '>=', now()->subDays(7))->count(),
            'interactions_30d' => (clone $baseInteractions)->where('created_at', '>=', now()->subDays(30))->count(),
            'interactions_90d' => (clone $baseInteractions)->where('created_at', '>=', now()->subDays(90))->count(),
            'logins_7d' => (clone $baseInteractions)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(7))->count(),
            'logins_30d' => (clone $baseInteractions)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(30))->count(),
            'logins_90d' => (clone $baseInteractions)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(90))->count(),
            'first_activity_at' => (clone $baseInteractions)->oldest('created_at')->value('created_at'),
            'last_activity_at' => $recentActivity->first()?->created_at,
            'last_login_at' => $logins->first()?->created_at,
            'applications' => $user->applications->count(),
            'establishments' => data_get($resources, 'establishments.total', 0),
            'productions' => data_get($resources, 'productions.total', 0),
            'items' => data_get($resources, 'items.total', 0),
            'employments' => data_get($resources, 'employments.total', 0),
            'team_members' => data_get($resources, 'team_members.total', 0),
            'events' => data_get($resources, 'events.total', 0),
            'orders' => data_get($resources, 'orders.total', 0),
            'event_passes' => data_get($resources, 'event_passes.total', 0),
        ];

        return [
            'user' => $this->userPayload($user),
            'summary' => $summary,
            'platforms' => $this->platforms($user, $applications, $usage, $resources),
            'resources' => $resources,
            'application_usage' => $usage->values(),
            'activity_by_type' => $this->activityByType($user),
            'activity_facets' => $this->activityFacets($user),
            'security' => [
                'alerts' => $securityAlerts,
                'ips' => $ips,
                'locations' => $locations,
                'devices' => $devices,
            ],
            'timeline' => $recentActivity
                ->take(80)
                ->map(fn (Interaction $interaction) => $this->interactionPayload($interaction))
                ->values(),
        ];
    }

    public function activity(User $user, array $data): array
    {
        $query = $this->interactionQuery($user);

        if (! empty($data['app_id'])) $query->where('app_id', (int) $data['app_id']);
        if (! empty($data['type'])) $query->where('interaction_type', $data['type']);
        if (! empty($data['outcome'])) $query->where('outcome', $data['outcome']);
        if (! empty($data['severity'])) $query->where('severity', $data['severity']);
        if (! empty($data['environment'])) $query->where('environment', $data['environment']);
        if (! empty($data['entity_type'])) $query->where('entity_type', $data['entity_type']);
        if (! empty($data['from'])) $query->where('created_at', '>=', $data['from']);
        if (! empty($data['to'])) $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));

        if ($search = trim((string) ($data['q'] ?? ''))) {
            $query->where(function (Builder $filter) use ($search) {
                $filter->where('name', 'like', "%{$search}%")
                    ->orWhere('interaction_type', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('request_id', 'like', "%{$search}%")
                    ->orWhere('correlation_id', 'like', "%{$search}%");
            });
        }

        ($data['sort'] ?? 'newest') === 'oldest' ? $query->oldest('created_at') : $query->latest('created_at');

        $paginator = $query->paginate((int) ($data['per_page'] ?? 50));
        $rows = collect($paginator->items())
            ->map(fn (Interaction $interaction) => $this->interactionPayload($interaction))
            ->values();

        return [
            'activity' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                'previous_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            ],
        ];
    }

    private function resources(User $user, Collection $applications): array
    {
        $establishments = $user->establishments->sortByDesc('created_at')->values();
        $establishmentIds = $establishments->pluck('id');
        $productions = $establishments
            ->filter(fn ($establishment) => strtolower((string) $establishment->category) === 'production')
            ->values();
        $productionIds = $productions->pluck('id');

        $employmentsQuery = Employer::query()
            ->with(['establishment:id,app_id,name,fantasy,slug,type,category'])
            ->where('user_id', $user->id);
        $employmentsTotal = (clone $employmentsQuery)->count();
        $employments = $employmentsQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();
        $employmentIds = $employments->pluck('id');

        $teamQuery = Employer::query()
            ->with(['user:id,first_name,last_name,user_name,email,avatar', 'establishment:id,app_id,name,fantasy,slug'])
            ->whereIn('establishment_id', $establishmentIds);
        $teamTotal = $establishmentIds->isEmpty() ? 0 : (clone $teamQuery)->count();
        $teamMembers = $establishmentIds->isEmpty() ? collect() : $teamQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();

        $itemsQuery = Item::query()
            ->with(['app:id,name,slug,logo,url', 'establishment:id,app_id,name,fantasy,slug'])
            ->where(function (Builder $query) use ($user, $establishmentIds) {
                $query->where('user_id', $user->id);
                if ($establishmentIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $entityQuery) use ($establishmentIds) {
                        $entityQuery->whereIn('entity_id', $establishmentIds)
                            ->whereIn('entity_name', ['establishment', 'Establishment']);
                    });
                }
            });
        $itemsTotal = (clone $itemsQuery)->count();
        $items = $itemsQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();

        $eventsQuery = Event::query()
            ->with(['application:id,name,slug,logo,url', 'production:id,app_id,name,fantasy,slug,category'])
            ->whereIn('production_id', $productionIds);
        $eventsTotal = $productionIds->isEmpty() ? 0 : (clone $eventsQuery)->count();
        $events = $productionIds->isEmpty() ? collect() : $eventsQuery->latest('start_date')->limit(self::RESOURCE_LIMIT)->get();
        $eventIds = $events->pluck('id');

        $ordersQuery = Order::query()->where(function (Builder $query) use ($user, $employmentIds, $establishmentIds) {
            $query->where('client_id', $user->id)
                ->orWhere('created_by', $user->id)
                ->orWhere('confirmed_by', $user->id)
                ->orWhere('cancelled_by', $user->id);
            if ($employmentIds->isNotEmpty()) $query->orWhereIn('attendant_id', $employmentIds);
            if ($establishmentIds->isNotEmpty()) {
                $query->orWhere(function (Builder $entityQuery) use ($establishmentIds) {
                    $entityQuery->whereIn('entity_id', $establishmentIds)
                        ->whereIn('entity_name', ['establishment', 'Establishment']);
                });
            }
        });
        $ordersTotal = (clone $ordersQuery)->count();
        $orders = $ordersQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();

        $passesQuery = EventPass::query()
            ->with(['event:id,app_id,production_id,title,slug,start_date,end_date', 'ticket:id,event_id,name,type,price'])
            ->where('user_id', $user->id);
        $passesTotal = (clone $passesQuery)->count();
        $passes = $passesQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();

        $ticketsQuery = Ticket::query()
            ->with('event:id,app_id,production_id,title,slug,start_date')
            ->whereIn('event_id', $eventIds);
        $ticketsTotal = $eventIds->isEmpty() ? 0 : (clone $ticketsQuery)->count();
        $tickets = $eventIds->isEmpty() ? collect() : $ticketsQuery->latest('id')->limit(self::RESOURCE_LIMIT)->get();

        return [
            'establishments' => $this->resourceGroup('Estabelecimentos', $establishments->count(), $establishments->take(self::RESOURCE_LIMIT)->map(fn ($row) => $this->establishmentPayload($row, $applications))),
            'productions' => $this->resourceGroup('Produções', $productions->count(), $productions->take(self::RESOURCE_LIMIT)->map(fn ($row) => $this->establishmentPayload($row, $applications))),
            'items' => $this->resourceGroup('Itens', $itemsTotal, $items->map(fn (Item $row) => $this->itemPayload($row, $applications))),
            'employments' => $this->resourceGroup('Vínculos como employer', $employmentsTotal, $employments->map(fn (Employer $row) => $this->employmentPayload($row, $applications))),
            'team_members' => $this->resourceGroup('Equipe dos estabelecimentos', $teamTotal, $teamMembers->map(fn (Employer $row) => $this->teamPayload($row, $applications))),
            'events' => $this->resourceGroup('Eventos', $eventsTotal, $events->map(fn (Event $row) => $this->eventPayload($row, $applications))),
            'orders' => $this->resourceGroup('Pedidos e agendamentos', $ordersTotal, $orders->map(fn (Order $row) => $this->orderPayload($row, $applications, $user, $employmentIds, $establishmentIds))),
            'event_passes' => $this->resourceGroup('Ingressos e participações', $passesTotal, $passes->map(fn (EventPass $row) => $this->passPayload($row, $applications))),
            'tickets' => $this->resourceGroup('Lotes/ingressos dos eventos', $ticketsTotal, $tickets->map(fn (Ticket $row) => $this->ticketPayload($row, $applications))),
        ];
    }

    private function resourceGroup(string $label, int $total, Collection $rows): array
    {
        return ['label' => $label, 'total' => $total, 'truncated' => $total > self::RESOURCE_LIMIT, 'data' => $rows->values()];
    }

    private function platforms(User $user, Collection $applications, Collection $usage, array $resources): Collection
    {
        $accessByApp = $user->applications->keyBy('id');
        $usageByApp = $usage->keyBy('app_id');
        $resourceCounts = [];
        $appIds = collect($accessByApp->keys())->merge($usageByApp->keys());

        foreach ($resources as $key => $group) {
            foreach ($group['data'] as $row) {
                $appId = data_get($row, 'app_id');
                if (! $appId) continue;
                $appIds->push((int) $appId);
                $resourceCounts[$appId][$key] = ($resourceCounts[$appId][$key] ?? 0) + 1;
            }
        }

        return $appIds->filter()->unique()->map(function ($appId) use ($applications, $accessByApp, $usageByApp, $resourceCounts) {
            $application = $applications->get((int) $appId);
            $access = $accessByApp->get((int) $appId);
            $usageRow = $usageByApp->get((int) $appId);
            return [
                'application' => $application ? $application->toArray() : ['id' => (int) $appId, 'name' => "Aplicação #{$appId}"],
                'access' => $access ? [
                    'status' => $access->pivot?->status,
                    'role' => $access->pivot?->role,
                    'joined_at' => $access->pivot?->joined_at,
                    'metadata' => $access->pivot?->metadata,
                ] : null,
                'usage' => $usageRow,
                'resources' => $resourceCounts[$appId] ?? [],
            ];
        })->sortByDesc(fn ($platform) => data_get($platform, 'usage.total', 0))->values();
    }

    private function applicationUsage(User $user, Collection $applications): Collection
    {
        return Interaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('app_id')
            ->selectRaw('app_id, COUNT(*) total, MAX(created_at) last_activity_at')
            ->groupBy('app_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) use ($applications) {
                $application = $applications->get((int) $row->app_id);
                return [
                    'app_id' => (int) $row->app_id,
                    'application' => $application?->toArray(),
                    'total' => (int) $row->total,
                    'last_activity_at' => $row->last_activity_at,
                ];
            });
    }

    private function activityByType(User $user): Collection
    {
        return Interaction::query()
            ->where('user_id', $user->id)
            ->selectRaw('interaction_type, COUNT(*) total, MAX(created_at) last_activity_at')
            ->groupBy('interaction_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['type' => $row->interaction_type, 'total' => (int) $row->total, 'last_activity_at' => $row->last_activity_at]);
    }

    private function activityFacets(User $user): array
    {
        $base = fn () => Interaction::query()->where('user_id', $user->id);
        return [
            'types' => $base()->whereNotNull('interaction_type')->distinct()->orderBy('interaction_type')->pluck('interaction_type')->values(),
            'outcomes' => $base()->whereNotNull('outcome')->distinct()->orderBy('outcome')->pluck('outcome')->values(),
            'severities' => $base()->whereNotNull('severity')->distinct()->orderBy('severity')->pluck('severity')->values(),
            'environments' => $base()->whereNotNull('environment')->distinct()->orderBy('environment')->pluck('environment')->values(),
            'entity_types' => $base()->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type')->values(),
        ];
    }

    private function interactionQuery(User $user): Builder
    {
        return Interaction::query()->where('user_id', $user->id)->with('application:id,name,slug,logo,url');
    }

    private function interactionPayload(Interaction $interaction): array
    {
        return [
            'id' => $interaction->id,
            'type' => $interaction->interaction_type,
            'name' => $interaction->name,
            'outcome' => $interaction->outcome,
            'severity' => $interaction->severity,
            'environment' => $interaction->environment,
            'entity_type' => $interaction->entity_type,
            'entity_id' => $interaction->entity_id,
            'route' => $interaction->route,
            'method' => $interaction->method,
            'request_id' => $interaction->request_id,
            'correlation_id' => $interaction->correlation_id,
            'parent_interaction_id' => $interaction->parent_interaction_id,
            'session_key' => $interaction->session_key,
            'content' => $this->sanitizeTelemetry($interaction->content),
            'application' => $interaction->application,
            'created_at' => $interaction->created_at,
        ];
    }

    private function userPayload(User $user): array
    {
        $cpfDigits = preg_replace('/\D+/', '', (string) $user->cpf);
        $maskedCpf = strlen($cpfDigits) === 11 ? '***.' . substr($cpfDigits, 3, 3) . '.' . substr($cpfDigits, 6, 3) . '-**' : null;
        return [
            'id' => $user->id,
            'user_name' => $user->user_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'address' => $user->address,
            'city' => $user->city,
            'uf' => $user->uf,
            'postal_code' => $user->postal_code,
            'birthdate' => $user->birthdate,
            'occupation' => $user->occupation,
            'about' => $user->about,
            'cpf_masked' => $maskedCpf,
            'email_verified_at' => $user->email_verified_at,
            'profile_id' => $user->profile_id,
            'profile' => $user->profile,
            'roles' => [
                'producer' => (bool) $user->is_producer,
                'participant' => (bool) $user->is_participant,
                'promoter' => (bool) $user->is_promoter,
                'barber' => (bool) $user->is_barber,
                'barbershop_owner' => (bool) $user->is_barbershoper,
                'partner' => (bool) $user->is_partner,
                'ticket_seller' => (bool) $user->is_ticket_seller,
            ],
            'applications' => $user->applications,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function establishmentPayload($row, Collection $applications): array
    {
        return [
            'id' => $row->id, 'app_id' => $row->app_id, 'application' => $this->appPayload($applications, $row->app_id),
            'name' => $row->fantasy ?: $row->name, 'legal_name' => $row->name, 'slug' => $row->slug,
            'type' => $row->type, 'category' => $row->category, 'city' => $row->city, 'uf' => $row->uf,
            'is_approved' => (bool) $row->is_approved, 'is_published' => (bool) $row->is_published,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function itemPayload(Item $row, Collection $applications): array
    {
        $appId = $row->app_id ?: $row->establishment?->app_id;
        return [
            'id' => $row->id, 'app_id' => $appId, 'application' => $this->appPayload($applications, $appId),
            'name' => $row->name, 'slug' => $row->slug, 'type' => $row->type, 'category' => $row->category,
            'price' => $row->price, 'stock' => $row->stock, 'status' => (bool) $row->status,
            'entity_id' => $row->entity_id, 'entity_name' => $row->entity_name,
            'establishment' => $row->establishment ? ['id' => $row->establishment->id, 'name' => $row->establishment->fantasy ?: $row->establishment->name, 'slug' => $row->establishment->slug] : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function employmentPayload(Employer $row, Collection $applications): array
    {
        $appId = $row->establishment?->app_id;
        return [
            'id' => $row->id, 'app_id' => $appId, 'application' => $this->appPayload($applications, $appId),
            'name' => $row->role ?: 'Employer', 'role' => $row->role, 'permissions' => $row->permissions,
            'establishment' => $row->establishment ? [
                'id' => $row->establishment->id, 'name' => $row->establishment->fantasy ?: $row->establishment->name,
                'slug' => $row->establishment->slug, 'type' => $row->establishment->type, 'category' => $row->establishment->category,
            ] : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function teamPayload(Employer $row, Collection $applications): array
    {
        $payload = $this->employmentPayload($row, $applications);
        $payload['user'] = $row->user ? [
            'id' => $row->user->id,
            'name' => trim($row->user->first_name . ' ' . $row->user->last_name),
            'user_name' => $row->user->user_name,
            'email' => $row->user->email,
            'avatar' => $row->user->avatar,
        ] : null;
        return $payload;
    }

    private function eventPayload(Event $row, Collection $applications): array
    {
        return [
            'id' => $row->id, 'app_id' => $row->app_id, 'application' => $this->appPayload($applications, $row->app_id),
            'name' => $row->title, 'title' => $row->title, 'slug' => $row->slug, 'category' => $row->category,
            'start_date' => $row->start_date, 'end_date' => $row->end_date, 'city' => $row->city, 'uf' => $row->uf,
            'is_published' => (bool) $row->is_published, 'is_approved' => (bool) $row->is_approved, 'is_cancelled' => (bool) $row->is_cancelled,
            'production' => $row->production ? ['id' => $row->production->id, 'name' => $row->production->fantasy ?: $row->production->name, 'slug' => $row->production->slug] : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function orderPayload(Order $row, Collection $applications, User $user, Collection $employmentIds, Collection $establishmentIds): array
    {
        $links = collect();
        if ((int) $row->client_id === (int) $user->id) $links->push('cliente');
        if ((int) $row->created_by === (int) $user->id) $links->push('criador');
        if ((int) $row->confirmed_by === (int) $user->id) $links->push('confirmou');
        if ((int) $row->cancelled_by === (int) $user->id) $links->push('cancelou');
        if ($employmentIds->contains((int) $row->attendant_id)) $links->push('atendente');
        if ($establishmentIds->contains((int) $row->entity_id)) $links->push('estabelecimento');
        return [
            'id' => $row->id, 'app_id' => $row->app_id, 'application' => $this->appPayload($applications, $row->app_id),
            'name' => $row->order_number ?: "Pedido #{$row->id}", 'order_number' => $row->order_number,
            'type' => $row->type, 'status' => $row->status, 'appointment_status' => $row->appointment_status,
            'payment_status' => $row->payment_status, 'total_price' => $row->total_price, 'order_datetime' => $row->order_datetime,
            'link_types' => $links->unique()->values(), 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function passPayload(EventPass $row, Collection $applications): array
    {
        $appId = $row->event?->app_id;
        return [
            'id' => $row->id, 'app_id' => $appId, 'application' => $this->appPayload($applications, $appId),
            'name' => $row->event?->title ?: "Ingresso #{$row->id}", 'status' => $row->status,
            'holder_name' => $row->holder_name, 'holder_email' => $row->holder_email, 'checked_in_at' => $row->checked_in_at,
            'event' => $row->event ? ['id' => $row->event->id, 'title' => $row->event->title, 'slug' => $row->event->slug, 'start_date' => $row->event->start_date] : null,
            'ticket' => $row->ticket ? ['id' => $row->ticket->id, 'name' => $row->ticket->name, 'type' => $row->ticket->type, 'price' => $row->ticket->price] : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function ticketPayload(Ticket $row, Collection $applications): array
    {
        $appId = $row->app_id ?: $row->event?->app_id;
        return [
            'id' => $row->id, 'app_id' => $appId, 'application' => $this->appPayload($applications, $appId),
            'name' => $row->name, 'type' => $row->type, 'ticket_type' => $row->ticket_type,
            'price' => $row->price, 'quantity' => $row->quantity, 'limit_date' => $row->limit_date,
            'event' => $row->event ? ['id' => $row->event->id, 'title' => $row->event->title, 'slug' => $row->event->slug, 'start_date' => $row->event->start_date] : null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function appPayload(Collection $applications, $appId): ?array
    {
        return $appId ? $applications->get((int) $appId)?->toArray() : null;
    }

    private function sanitizeTelemetry($value)
    {
        if (! is_array($value)) return is_string($value) && strlen($value) > 4000 ? substr($value, 0, 4000) . '…' : $value;
        $sensitive = ['password', 'token', 'authorization', 'cookie', 'secret', 'api_key', 'access_token', 'refresh_token'];
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (collect($sensitive)->contains(fn ($needle) => str_contains($normalized, $needle))) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            $result[$key] = $this->sanitizeTelemetry($item);
        }
        return $result;
    }

    private function deviceLabel(?string $agent): ?string
    {
        if (! $agent) return null;
        $browser = str_contains($agent, 'Edg/') ? 'Edge'
            : (str_contains($agent, 'Chrome/') ? 'Chrome'
                : (str_contains($agent, 'Firefox/') ? 'Firefox'
                    : (str_contains($agent, 'Safari/') ? 'Safari' : 'Outro navegador')));
        $device = str_contains($agent, 'Android') ? 'Android'
            : ((str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) ? 'iOS'
                : (str_contains($agent, 'Windows') ? 'Windows'
                    : (str_contains($agent, 'Macintosh') ? 'macOS' : 'Outro dispositivo')));
        return "{$browser} · {$device}";
    }
}
