<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use App\Traits\HasFiles; 

class Establishment extends Model
{   
     use HasFiles;
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
        'app_id',
        'uf'
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
            ->where('entity_name', 'establishment')
            ->withCount([
                'views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                },
                'views as unique_users' => function ($q) {
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view');
                },
            ]);
    }


    public function orders()
    {
        return $this->hasMany(Order::class, 'entity_id')
            ->where('entity_name', 'establishment');
    }



    public function employers()
    {
        return $this->hasMany(Employer::class)
            ->withCount([
                // 👁️ Visualizações gerais
                'views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                },
                'views as unique_users' => function ($q) {
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view');
                },

                // 💈 Total de atendimentos (pedidos confirmados ou atendidos)
                'orders as total_appointments' => function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended']);
                },

                // 💰 Total de receita gerada pelo colaborador (opcional)
                'orders as total_revenue' => function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended'])
                        ->select(\DB::raw('COALESCE(SUM(total_price),0)'));
                },
            ]);
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
            $views = $this->views();
            $items = $this->items();
            $employers = $this->employers();
            $orders = $this->orders();

            // 🔹 Totais básicos
            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalItems = $items->count();
            $totalEmployers = $employers->count();
            $totalOrders = $orders->count();

            // 🔹 Pedidos por status
            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();

            // 🔹 Receita e ticket médio
            $totalRevenue = (clone $orders)->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            // 🔹 Cálculo de engajamento
            $avgViewsPerUser = $uniqueUsers > 0 ? round($totalViews / $uniqueUsers, 2) : 0;
            $avgViewsPerItem = $totalItems > 0 ? round($totalViews / $totalItems, 2) : 0;
            $avgViewsPerEmployer = $totalEmployers > 0 ? round($totalViews / $totalEmployers, 2) : 0;

            // 🔹 Eficiência dos colaboradores (média de visualizações por colaborador)
            $totalEmployerViews = \App\Models\Interaction::where('entity_type', 'Employer')
                ->whereIn('entity_id', $employers->pluck('id'))
                ->where('interaction_type', 'view')
                ->count();

            $avgEmployerViews = $totalEmployers > 0 ? round($totalEmployerViews / $totalEmployers, 2) : 0;

            // 🔹 Frequência de atividade
            $firstView = $views->min('created_at');
            if ($firstView && !($firstView instanceof \Carbon\Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);

            // 🔹 Taxas de comportamento
            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

            // 🔹 Clientes recorrentes (SQL otimizado)
            $clientsCount = (clone $orders)
                ->selectRaw('client_id, COUNT(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
            $returnRate = $clientsCount->count() > 0
                ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
                : 0;

            // 🔹 Engajamento geral (pontuação simbólica)
            $engagementScore = round(
                ($uniqueUsers * 1.5) +
                ($totalViews * 0.2) +
                ($completedOrders * 1.2) +
                ($returnRate * 0.5),
                2
            );

            // 🔹 Receita média por colaborador
            $avgRevenuePerEmployer = $totalEmployers > 0 ? round($totalRevenue / $totalEmployers, 2) : 0;

            return [
                // 🧩 Estrutura geral
                'total_items' => $totalItems,
                'total_employers' => $totalEmployers,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,

                // 💰 Financeiro
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'avg_revenue_per_employer' => $avgRevenuePerEmployer,

                // 👁️ Engajamento
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_user' => $avgViewsPerUser,
                'avg_views_per_item' => $avgViewsPerItem,
                'avg_views_per_employer' => $avgViewsPerEmployer,
                'avg_employer_views' => $avgEmployerViews,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,
                'engagement_score' => $engagementScore,

                // 📈 Taxas
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'pending_rate' => $pendingRate,
                'efficiency_rate' => $efficiencyRate,
                'return_rate' => $returnRate,
            ];
        });

    }

    public function interactionSummary()
    {
        return Cache::remember("establishment_{$this->id}_summary", 120, function () {
            $views = $this->views()
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->get();

            if ($views->isEmpty()) {
                return [
                    'total_views' => 0,
                    'unique_users' => 0,
                    'most_active_user' => null,
                    'last_view_user' => null,
                ];
            }

            // 🔹 Usuário mais ativo
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

            // 🔹 Último visitante (com avatar e nome)
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
            $views = \App\Models\Interaction::where('entity_type', 'Establishment')
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
            ->with([
                'items' => function ($q) {
                    $q->select('id', 'entity_id', 'name', 'slug', 'price');
                }
            ])
            ->firstOrFail();
    }
    public function otherEstablishments()
    {
        return Cache::remember("establishment_{$this->id}_related", 120, function () {
            return self::where('app_id', $this->app_id)
                ->where('id', '!=', $this->id)
                ->withCount([
                    'views as total_views' => function ($q) {
                        $q->where('interaction_type', 'view');
                    },
                ])
                ->limit(6)
                ->get([
                    'id',
                    'name',
                    'slug',
                    'logo',
                    'background',
                    'city',
                    'category',
                ])
                ->map(function ($est) {
                    $est->completed_appointments = \App\Models\Order::where('entity_name', 'App\\Models\\Establishment')
                        ->where('entity_id', $est->id)
                        ->where('type', 'appointment')
                        ->where('appointment_status', 'attended')
                        ->count();
                    return $est;
                });
        });
    }

    public function otherEmployers()
    {
        return Cache::remember("establishment_{$this->id}_other_employers", 120, function () {
            return \App\Models\Employer::with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'establishment:id,name,slug,logo,background,app_id',
            ])
                ->whereHas('establishment', function ($q) {
                    $q->where('app_id', $this->app_id);
                })
                ->where('establishment_id', '!=', $this->id)
                ->withCount([
                    'views as total_views' => function ($q) {
                        $q->where('interaction_type', 'view');
                    },
                ])
                ->inRandomOrder()
                ->limit(6)
                ->get(['id', 'establishment_id'])
                ->map(function ($emp) {
                    $emp->completed_appointments = \App\Models\Order::where('attendant_id', $emp->id)
                        ->where('type', 'appointment')
                        ->where('appointment_status', 'attended')
                        ->count();
                    return $emp;
                });
        });
    }

    public function otherItems()
    {
        return Cache::remember("establishment_{$this->id}_other_items", 120, function () {
            return \App\Models\Item::with([
                'entity:id,name,slug,logo,background,app_id',
            ])
                ->whereHas('entity', function ($q) {
                    $q->where('app_id', $this->app_id);
                })
                ->where('entity_id', '!=', $this->id)
                ->withCount([
                    'views as total_views' => function ($q) {
                        $q->where('interaction_type', 'view');
                    },
                ])
                ->inRandomOrder()
                ->limit(6)
                ->get([
                    'id',
                    'entity_id',
                    'name',
                    'slug',
                    'price',
                    'type',
                    'image',
                ])
                ->map(function ($item) {
                    $item->completed_appointments = \App\Models\OrderItem::where('item_id', $item->id)
                        ->whereHas('order', function ($q) {
                            $q->where('type', 'appointment')
                                ->where('appointment_status', 'attended');
                        })
                        ->count();
                    return $item;
                });
        });
    }

    public function completedAppointments()
    {
        return Cache::remember("establishment_{$this->id}_completed_appointments", 120, function () {
            return \App\Models\Order::where('entity_name', 'establishment')
                ->where('entity_id', $this->id)
                ->where('type', 'appointment')
                ->where('appointment_status', 'attended')
                ->with([
                    'client:id,first_name,last_name,user_name,avatar,email',
                    'attendant.user:id,first_name,last_name,user_name,avatar,email',
                    'items:id,order_id,item_id,quantity',
                    'items.item:id,name,price,type'
                ])
                ->orderByDesc('attended_at')
                ->get()
                ->map(function ($order) {
                    $client = $order->client;
                    $attendant = $order->attendant?->user;

                    $totalItems = $order->items->sum('quantity');
                    $itemNames = $order->items->pluck('item.name')->toArray();

                    return [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'attended_at' => optional($order->attended_at)->format('d/m/Y H:i'),
                        'client' => $client ? [
                            'id' => $client->id,
                            'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                            'user_name' => $client->user_name,
                            'avatar' => $client->avatar,
                        ] : null,
                        'attendant' => $attendant ? [
                            'id' => $attendant->id,
                            'name' => trim(($attendant->first_name ?? '') . ' ' . ($attendant->last_name ?? '')),
                            'user_name' => $attendant->user_name,
                            'avatar' => $attendant->avatar,
                        ] : null,
                        'total_items' => $totalItems,
                        'item_list' => $itemNames,
                        'total_price' => $order->total_price,
                    ];
                });
        });
    }

    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', 'establishment')
            ->orderBy('position');
    }

    public function logoFile()
    {
        return $this->hasOne(File::class, 'entity_id')
            ->where('entity_name', 'establishment')
            ->where('type', 'logo');
    }

    public function backgroundFile()
    {
        return $this->hasOne(File::class, 'entity_id')
            ->where('entity_name', 'establishment')
            ->where('type', 'background');
    }

    public function media()
    {
        return $this->morphMany(File::class, 'fileable');
    }
protected static function booted()
{
    static::creating(function ($model) {
        $model->entity_name = 'establishment';
    });
}


}
