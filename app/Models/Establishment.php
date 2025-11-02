<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class Establishment extends Model
{
    protected $fillable = [
        'name',
        'fantasy',
        'slug',
        'cnpj',
        'type',
        'category',
        'phone',
        'email',
        'description',
        'additional_info',
        'city',
        'location',
        'cep',
        'address',
        'user_id',
        'updated_by',
        'created_by',
        'logo',
        'background',
        'is_featured',
        'is_published',
        'is_approved',
        'is_cancelled',
        'website_url',
        'facebook_url',
        'instagram_url',
        'twitter_url',
        'youtube_url',
        'segments',
        'app_id'
    ];

    protected $casts = [
        'segments' => 'json',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
        'is_cancelled' => 'boolean',
    ];

    // ⚙️ Removido para evitar loop infinito — tudo será chamado manualmente no controller
    protected $appends = ['metrics'];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->fantasy ?? $model->name);
            }
        });
    }

    /* =======================
       RELACIONAMENTOS
       ======================= */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function app()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'entity_id')
            ->where('entity_name', 'establishment');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'entity_id')
            ->where('entity_name', 'establishment');
    }



    public function employers()
    {
        return $this->hasMany(Employer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Establishment');
    }

    public function views()
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    /* =======================
       MÉTRICAS E INTERAÇÕES
       ======================= */

    public function getMetricsAttribute()
    {
        return Cache::remember("establishment_{$this->id}_metrics", 120, function () {
            return [
                'total_items' => $this->items()->count(),
                'total_employers' => $this->employers()->count(),
                'total_views' => $this->views()->count(),
                'unique_users' => $this->views()->pluck('user_id')->unique()->count(),
            ];
        });
    }

    public function interactionSummary()
    {
        return Cache::remember("establishment_{$this->id}_summary", 120, function () {
            $views = $this->views()->with('user:id,first_name,last_name,user_name,avatar,email')->get();

            $mostActive = $views->groupBy('user_id')->map(function ($g) {
                $u = $g->first()->user;
                return [
                    'user_id' => $u?->id,
                    'user_name' => $u?->user_name,
                    'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                    'avatar' => $u?->avatar,
                    'email' => $u?->email,
                    'total' => $g->count(),
                    'last_view' => $g->max('created_at'),
                ];
            })->sortByDesc('total')->first();

            return [
                'total_views' => $views->count(),
                'unique_users' => $views->pluck('user_id')->unique()->count(),
                'most_active_user' => $mostActive,
                'last_view_user' => $views->sortByDesc('created_at')->first()?->user,
            ];
        });
    }

    public function itemsInteractions()
    {
        return Cache::remember("establishment_{$this->id}_items_interactions", 120, function () {
            return $this->items->map(function ($item) {
                $views = $item->views()->with('user:id,first_name,last_name,user_name,avatar,email')->get();

                $mostActive = $views->groupBy('user_id')->map(function ($g) {
                    $u = $g->first()->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'avatar' => $u?->avatar,
                        'total_views' => $g->count(),
                    ];
                })->sortByDesc('total_views')->first();

                return [
                    'item_id' => $item->id,
                    'total_views' => $views->count(),
                    'unique_users' => $views->pluck('user_id')->unique()->count(),
                    'most_active_user' => $mostActive,
                ];
            });
        });
    }

    public function userInteractions()
    {
        return Cache::remember("establishment_{$this->id}_user_interactions", 120, function () {
            $userIds = collect()
                ->merge($this->views()->pluck('user_id')->toArray())
                ->merge($this->items->flatMap(fn($i) => $i->views()->pluck('user_id')->toArray()))
                ->merge($this->employers->flatMap(fn($e) => $e->views()->pluck('user_id')->toArray()))
                ->filter()
                ->unique()
                ->values();

            return User::whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'email'])
                ->map(function ($u) {
                    return [
                        'user_id' => $u->id,
                        'user_name' => $u->user_name,
                        'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                        'avatar' => $u->avatar,
                        'email' => $u->email,
                        'profile_link' => $u->user_name ? url("/user/view/{$u->user_name}") : null,
                    ];
                });
        });
    }

    public function otherEstablishments()
    {
        return Cache::remember("establishment_{$this->id}_related", 120, function () {
            return self::where('app_id', $this->app_id)
                ->where('id', '!=', $this->id)
                ->withCount(['views as total_views'])
                ->limit(6)
                ->get(['id', 'name', 'slug', 'logo', 'city', 'category']);
        });
    }

    /* =======================
       SEGMENTOS
       ======================= */

    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments)
            ? json_decode($this->segments, true)
            : ($this->segments ?? []);

        if (empty($segmentsArray)) {
            return '<i>Nenhum seguimento atribuído</i>';
        }

        $names = [];
        $segmentsConfig = Config::get('segments', []);

        foreach ($segmentsArray as $key) {
            if (isset($segmentsConfig[$key])) {
                $names[] = $segmentsConfig[$key]['name'];
            }
        }

        return implode(' | ', $names);
    }

public function ordersSummary()
{
    return Cache::remember("establishment_{$this->id}_orders_summary", 120, function () {
        $orders = $this->orders()
            ->with(['client:id,first_name,last_name,user_name,avatar,email'])
            ->get();

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
                'average_service_time' => 0,
                'return_rate' => 0,
                'top_client_by_count' => null,
                'top_client_by_value' => null,
                'top_client_completed' => null,
                'top_recurring_client' => null,
                'active_days' => 0,
            ];
        }

        // 🔹 Total geral de pedidos
        $totalOrders = $orders->count();

        // 🔹 Status baseados em appointment_status
        $completedOrders = $orders->whereIn('appointment_status', ['confirmed', 'attended'])->count();
        $cancelledOrders = $orders->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
        $pendingOrders = $orders->where('appointment_status', 'pending')->count();

        // 🔹 Cálculo de receita e ticket médio
        $totalRevenue = $orders->sum('total_price');
        $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

        // 🔹 Taxas comportamentais
        $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
        $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
        $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
        $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
            ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
            : 0;

        // 🔹 Tempo médio entre criação e conclusão
        $averageServiceTime = 0;
        $completedWithDates = $orders->whereNotNull('created_at')->whereNotNull('updated_at');
        if ($completedWithDates->count() > 0) {
            $averageServiceTime = round(
                $completedWithDates->map(function ($o) {
                    return $o->updated_at->diffInMinutes($o->created_at);
                })->avg(),
                2
            );
        }

        // 🔹 Clientes recorrentes
        $clientsCount = $orders->groupBy('client_id')->map->count();
        $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
        $returnRate = $clientsCount->count() > 0
            ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
            : 0;

        $topRecurringClient = null;
        if ($recurringClients->isNotEmpty()) {
            $topRecurringId = $recurringClients->sortDesc()->keys()->first();
            $client = $orders->firstWhere('client_id', $topRecurringId)?->client;
            $topRecurringClient = [
                'id' => $client?->id,
                'name' => trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')),
                'user_name' => $client?->user_name,
                'avatar' => $client?->avatar,
                'repeat_orders' => $recurringClients[$topRecurringId],
            ];
        }

        // 🔹 Dias de atividade
        $activeDays = $orders->pluck('created_at')->map(fn($d) => $d->toDateString())->unique()->count();

        // 🔹 Cliente com mais pedidos
        $topClientByCount = $orders->groupBy('client_id')
            ->map(function ($group) {
                $client = $group->first()->client;
                return [
                    'id' => $client?->id,
                    'name' => trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')),
                    'user_name' => $client?->user_name,
                    'avatar' => $client?->avatar,
                    'total_orders' => $group->count(),
                ];
            })
            ->sortByDesc('total_orders')
            ->first();

        // 🔹 Cliente que mais gastou
        $topClientByValue = $orders->groupBy('client_id')
            ->map(function ($group) {
                $client = $group->first()->client;
                return [
                    'id' => $client?->id,
                    'name' => trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')),
                    'user_name' => $client?->user_name,
                    'avatar' => $client?->avatar,
                    'total_spent' => $group->sum('total_price'),
                    'total_orders' => $group->count(),
                ];
            })
            ->sortByDesc('total_spent')
            ->first();

        // 🔹 Cliente com mais pedidos concluídos
        $topClientByCompleted = $orders->whereIn('appointment_status', ['confirmed', 'attended'])
            ->groupBy('client_id')
            ->map(function ($group) {
                $client = $group->first()->client;
                return [
                    'id' => $client?->id,
                    'name' => trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')),
                    'user_name' => $client?->user_name,
                    'avatar' => $client?->avatar,
                    'completed_orders' => $group->count(),
                ];
            })
            ->sortByDesc('completed_orders')
            ->first();

        // 🔹 Retorno final
        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'pending_orders' => $pendingOrders,
            'total_revenue' => $totalRevenue,
            'average_ticket' => $averageTicket,
            'cancellation_rate' => $cancellationRate,
            'completion_rate' => $completionRate,
            'pending_rate' => $pendingRate,
            'efficiency_rate' => $efficiencyRate,
            'average_service_time' => $averageServiceTime,
            'return_rate' => $returnRate,
            'top_client_by_count' => $topClientByCount,
            'top_client_by_value' => $topClientByValue,
            'top_client_completed' => $topClientByCompleted,
            'top_recurring_client' => $topRecurringClient,
            'active_days' => $activeDays,
        ];
    });
}


    /**
     * Retorna um estabelecimento com os itens otimizados (para o carrossel).
     *
     * @param string $slug
     * @return \App\Models\Establishment|null
     */
    public static function withLightItems($slug)
{
    return self::where('slug', $slug)
        ->with(['items' => function ($q) {
            $q->select('id', 'entity_id', 'name', 'slug', 'price');
        }])
        ->firstOrFail();
}


}
