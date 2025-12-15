<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Traits\HasFiles;

class Employer extends Model
{
    use HasFiles;

    protected $fillable = [
        'user_id',
        'establishment_id',
        'role',
        'permissions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'permissions' => 'json',
    ];

    protected $appends = ['metrics'];

protected $entity_name = 'employer';      

    /* ==========================
       RELACIONAMENTOS
       ========================== */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'attendant_id');
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Employer');
    }

    public function views()
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    /* ==========================
       MÉTRICAS E INTERAÇÕES
       ========================== */

    public function getMetricsAttribute()
    {
        return Cache::remember("employer_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $orders = $this->orders();

            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalOrders = $orders->count();
            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();

            $totalRevenue = (clone $orders)->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

            $firstView = $views->min('created_at');
            if ($firstView && !($firstView instanceof \Carbon\Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);

            $clientsCount = (clone $orders)
                ->selectRaw('client_id, COUNT(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
            $returnRate = $clientsCount->count() > 0
                ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
                : 0;

            $engagementScore = round(
                ($uniqueUsers * 1.5) +
                ($totalViews * 0.2) +
                ($completedOrders * 1.2) +
                ($returnRate * 0.5),
                2
            );

            return [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'pending_rate' => $pendingRate,
                'efficiency_rate' => $efficiencyRate,
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,
                'return_rate' => $returnRate,
                'engagement_score' => $engagementScore,
            ];
        });
    }

    public function interactionSummary()
    {
        return Cache::remember("employer_{$this->id}_summary", 120, function () {
            $views = $this->views()->with('user:id,first_name,last_name,user_name,avatar,email')->get();
            if ($views->isEmpty()) {
                return [
                    'total_views' => 0,
                    'unique_users' => 0,
                    'most_active_user' => null,
                    'last_view_user' => null,
                ];
            }

            $mostActive = $views->groupBy('user_id')->map(function ($g) {
                $u = $g->first()->user;
                return [
                    'user_id' => $u?->id,
                    'user_name' => $u?->user_name,
                    'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                    'avatar' => $u?->avatar,
                    'email' => $u?->email,
                    'total' => $g->count(),
                ];
            })->sortByDesc('total')->first();

            $lastView = $views->sortByDesc('created_at')->first()?->user;
            $lastViewUser = $lastView ? [
                'user_id' => $lastView->id,
                'user_name' => $lastView->user_name,
                'name' => trim(($lastView->first_name ?? '') . ' ' . ($lastView->last_name ?? '')),
                'avatar' => $lastView->avatar,
                'email' => $lastView->email,
            ] : null;

            return [
                'total_views' => $views->count(),
                'unique_users' => $views->pluck('user_id')->unique()->count(),
                'most_active_user' => $mostActive,
                'last_view_user' => $lastViewUser,
            ];
        });
    }

    public function ordersSummary()
    {
        return Cache::remember("employer_{$this->id}_orders_summary", 120, function () {
            $orders = $this->orders()->with('client:id,first_name,last_name,user_name,avatar,email')->get();

            if ($orders->isEmpty()) {
                return [
                    'total_orders' => 0,
                    'completed_orders' => 0,
                    'cancelled_orders' => 0,
                    'pending_orders' => 0,
                    'total_revenue' => 0,
                    'average_ticket' => 0,
                    'cancellation_rate' => 0,
                    'completion_rate' => 0,
                    'pending_rate' => 0,
                    'efficiency_rate' => 0,
                    'return_rate' => 0,
                    'top_client_by_count' => null,
                    'top_client_by_value' => null,
                    'top_client_completed' => null,
                ];
            }

            $totalOrders = $orders->count();
            $completedOrders = $orders->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = $orders->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = $orders->where('appointment_status', 'pending')->count();
            $totalRevenue = $orders->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

            $clientsCount = $orders->groupBy('client_id')->map->count();
            $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
            $returnRate = $clientsCount->count() > 0
                ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
                : 0;

            $topClientByCount = $orders->groupBy('client_id')->map(function ($group) {
                $c = $group->first()->client;
                return [
                    'id' => $c?->id,
                    'name' => trim(($c?->first_name ?? '') . ' ' . ($c?->last_name ?? '')),
                    'user_name' => $c?->user_name,
                    'avatar' => $c?->avatar,
                    'total_orders' => $group->count(),
                ];
            })->sortByDesc('total_orders')->first();

            $topClientByValue = $orders->groupBy('client_id')->map(function ($group) {
                $c = $group->first()->client;
                return [
                    'id' => $c?->id,
                    'name' => trim(($c?->first_name ?? '') . ' ' . ($c?->last_name ?? '')),
                    'user_name' => $c?->user_name,
                    'avatar' => $c?->avatar,
                    'total_spent' => $group->sum('total_price'),
                ];
            })->sortByDesc('total_spent')->first();

            $topClientCompleted = $orders->whereIn('appointment_status', ['confirmed', 'attended'])
                ->groupBy('client_id')
                ->map(function ($group) {
                    $c = $group->first()->client;
                    return [
                        'id' => $c?->id,
                        'name' => trim(($c?->first_name ?? '') . ' ' . ($c?->last_name ?? '')),
                        'user_name' => $c?->user_name,
                        'avatar' => $c?->avatar,
                        'completed_orders' => $group->count(),
                    ];
                })->sortByDesc('completed_orders')->first();

            return [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'pending_rate' => $pendingRate,
                'efficiency_rate' => $efficiencyRate,
                'return_rate' => $returnRate,
                'top_client_by_count' => $topClientByCount,
                'top_client_by_value' => $topClientByValue,
                'top_client_completed' => $topClientCompleted,
            ];
        });
    }

    /* ==========================
       AUXILIARES
       ========================== */

    public function refreshViewMetrics($viewer = null)
    {
        Interaction::registerView($this, $viewer);
        Cache::forget("employer_{$this->id}_metrics");
        Cache::forget("employer_{$this->id}_summary");
        Cache::forget("employer_{$this->id}_orders_summary");
    }
    public function userInteractions()
    {
        return Cache::remember("employer_{$this->id}_user_interactions", 120, function () {
            $views = \App\Models\Interaction::where('entity_type', 'Employer')
                ->where('entity_id', $this->id)
                ->where('interaction_type', 'view')
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->orderByDesc('created_at')
                ->get()
                ->filter(fn($v) => $v->user);

            $grouped = $views->groupBy('user_id')->map(function ($group) {
                $view = $group->first();
                $u = $view->user;

                return [
                    'user_id' => $u->id,
                    'user_name' => $u->user_name,
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'avatar' => $u->avatar,
                    'email' => $u->email,
                    'last_interaction' => $view->created_at
                        ? $view->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
                        : null,
                    'profile_link' => $u->user_name ? url("/user/view/{$u->user_name}") : null,
                ];
            });

            return $grouped->values();
        });
    }
    public static function validateEmployer($employerId, $establishmentId)
    {
        return self::where('id', $employerId)
            ->where('establishment_id', $establishmentId)
            ->exists();
    }
    public function colleagues()
    {
        if (!$this->establishment_id) {
            return collect(); // sem estabelecimento, sem colegas
        }

        return Cache::remember("employer_{$this->id}_colleagues_list", 120, function () {
            $colleagues = self::where('establishment_id', $this->establishment_id)
                ->where('id', '!=', $this->id)
                ->with(['user:id,first_name,last_name,user_name,avatar,email'])
                ->get()
                ->map(function ($col) {
                    $u = $col->user;
                    $metrics = $col->metrics;

                    return [
                        'id' => $col->id,
                        'user_id' => $u?->id,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'user_name' => $u?->user_name,
                        'avatar' => $u?->avatar,
                        'email' => $u?->email,
                        'metrics' => [
                            'total_views' => $metrics['total_views'] ?? 0,
                            'unique_users' => $metrics['unique_users'] ?? 0,
                            'total_orders' => $metrics['total_orders'] ?? 0,
                            'engagement_score' => $metrics['engagement_score'] ?? 0,
                        ],
                    ];
                });

            $avgEngagement = $colleagues->avg(fn($c) => $c['metrics']['engagement_score'] ?? 0);

            return [
                'list' => $colleagues->values(),
                'average_engagement_score' => round($avgEngagement, 2),
            ];
        });
    }
    public function topItemAndClient()
    {
        return Cache::remember("employer_{$this->id}_top_item_client", 120, function () {
            // Pega apenas pedidos concluídos/atendidos
            $orders = $this->orders()
                ->whereIn('appointment_status', ['confirmed', 'attended'])
                ->with([
                    'items.item:id,name,slug,image',
                    'client:id,first_name,last_name,user_name,avatar,email'
                ])
                ->get();

            if ($orders->isEmpty()) {
                return [
                    'top_item' => null,
                    'top_client_for_item' => null,
                    'total_attended_orders' => 0,
                ];
            }

            // Conta quantas vezes cada item foi atendido por este employer
            $itemCount = [];
            foreach ($orders as $order) {
                foreach ($order->items as $orderItem) {
                    $itemId = $orderItem->item_id;
                    $itemCount[$itemId] = ($itemCount[$itemId] ?? 0) + $orderItem->quantity;
                }
            }

            // Identifica o item mais atendido
            arsort($itemCount);
            $topItemId = array_key_first($itemCount);

            if (!$topItemId) {
                return [
                    'top_item' => null,
                    'top_client_for_item' => null,
                    'total_attended_orders' => $orders->count(),
                ];
            }

            // Carrega o item completo
            $topItem = \App\Models\Item::find($topItemId);
            if (!$topItem) {
                return [
                    'top_item' => null,
                    'top_client_for_item' => null,
                    'total_attended_orders' => $orders->count(),
                ];
            }

            // Agora, conta qual cliente mais fez esse item específico
            $clientCount = [];
            foreach ($orders as $order) {
                foreach ($order->items as $orderItem) {
                    if ($orderItem->item_id === $topItemId && $order->client_id) {
                        $clientCount[$order->client_id] = ($clientCount[$order->client_id] ?? 0) + $orderItem->quantity;
                    }
                }
            }

            arsort($clientCount);
            $topClientId = array_key_first($clientCount);
            $topClient = null;

            if ($topClientId) {
                $client = \App\Models\User::find($topClientId);
                if ($client) {
                    $topClient = [
                        'id' => $client->id,
                        'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                        'user_name' => $client->user_name,
                        'avatar' => $client->avatar,
                        'email' => $client->email,
                        'total_attended_for_item' => $clientCount[$topClientId] ?? 0,
                    ];
                }
            }

            return [
                'top_item' => [
                    'id' => $topItem->id,
                    'name' => $topItem->name,
                    'slug' => $topItem->slug,
                    'image' => $topItem->image,
                    'total_attended' => $itemCount[$topItemId] ?? 0,
                ],
                'top_client_for_item' => $topClient,
                'total_attended_orders' => $orders->count(),
            ];
        });
    }


    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', 'employer')
            ->orderBy('position');
    }
/* ============================================================================
   OTHERS — PADRÃO PARA Establishment, Employer e Item
   ============================================================================
*/

/**
 * Outros estabelecimentos do mesmo app.
 */
public function otherEstablishments()
{
    $appId = $this->app_id ?? $this->establishment?->app_id ?? null;

    if (!$appId) {
        return collect();
    }

    return Cache::remember("{$this->entity_name}_{$this->id}_other_establishments", 120, function () use ($appId) {
        return \App\Models\Establishment::where('app_id', $appId)
            ->where('id', '!=', $this->id)
            ->with(['files' => fn($q) => $q->where('entity_name', 'establishment')])
            ->withCount(['views as total_views' => fn($q) =>
                $q->where('interaction_type', 'view')
            ])
            ->limit(6)
            ->get()
            ->map(function ($est) {

                $logo = $est->files->firstWhere('type', 'logo')?->public_url;
                $background = $est->files->firstWhere('type', 'background')?->public_url;

                return [
                    'id' => $est->id,
                    'name' => $est->name,
                    'slug' => $est->slug,
                    'city' => $est->city,
                    'category' => $est->category,

                    'logo' => $logo,
                    'background' => $background,

                    'images' => [
                        'logo' => $logo,
                        'background' => $background,
                        'gallery' => $est->files
                            ->whereNotIn('type', ['logo', 'background'])
                            ->pluck('public_url')
                            ->values()
                    ],

                    'total_views' => $est->total_views ?? 0,
                ];
            });
    });
}

/**
 * Outros colaboradores (employers) do mesmo app.
 */
public function otherEmployers()
{
    $appId = $this->app_id ?? $this->establishment?->app_id ?? null;
    $establishmentId = $this->establishment_id ?? null;

    if (!$appId) {
        return collect();
    }

    return Cache::remember("{$this->entity_name}_{$this->id}_other_employers", 120, function () use ($appId, $establishmentId) {

        // Estabelecimentos do mesmo app
        $estIds = \App\Models\Establishment::where('app_id', $appId)
            ->pluck('id');

        return \App\Models\Employer::whereIn('establishment_id', $estIds)
            ->where('id', '!=', $this->id)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) =>
                    $q->where('entity_name', 'employer'),
            ])
            ->withCount([
                'views as total_views' => fn($q) =>
                    $q->where('interaction_type', 'view'),
            ])
            ->limit(6)
            ->get()
            ->map(function ($emp) {

                $u = $emp->user;

                // ?? EXATAMENTE IGUAL AO EMPLOYERCONTROLLER::HOME
                $avatar = $emp->files->firstWhere('type', 'avatar')?->public_url
                    ?? $u?->avatar;

                return [
                    'id' => $emp->id,
                    'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                    'user_name' => $u?->user_name,

                    'avatar' => $avatar,

                    'city' => $emp->establishment?->city,
                    'uf' => $emp->establishment?->uf,

                    'total_views' => $emp->total_views ?? 0,

                    // Mesmo formato da home
                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $emp->files
                            ->whereNotIn('type', ['avatar'])
                            ->pluck('public_url')
                            ->values(),
                    ],

                    'establishment' => [
                        'name' => $emp->establishment?->name,
                        'slug' => $emp->establishment?->slug,
                    ]
                ];
            });
    });
}

/**
 * Outros itens do mesmo app.
 */
public function otherItems()
{
    $appId = $this->app_id ?? $this->establishment?->app_id ?? null;

    if (!$appId) {
        return collect();
    }

    return Cache::remember("{$this->entity_name}_{$this->id}_other_items", 120, function () use ($appId) {
        return \App\Models\Item::where('id', '!=', $this->id)
            ->whereHas('entity', fn($q) => $q->where('app_id', $appId))
            ->with([
                'files' => fn($q) => $q->where('entity_name', 'item'),
            ])
            ->withCount([
                'views as total_views' => fn($q) =>
                    $q->where('interaction_type', 'view'),
            ])
            ->limit(6)
            ->get()
            ->map(function ($item) {

                $image = $item->files->firstWhere('type', 'image')?->public_url
                    ?? $item->image;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'type' => $item->type,
                    'image' => $image,
                    'total_views' => $item->total_views ?? 0,
                ];
            });
    });
}

}
