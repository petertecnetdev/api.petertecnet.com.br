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

    protected $appends = ['metrics'];

    protected $entity_name = 'establishment';

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->fantasy ?? $model->name);
            }
        });
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->entity_name = 'establishment';
        });
    }

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
                'views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                },
                'views as unique_users' => function ($q) {
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view');
                },
                'orders as total_appointments' => function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended']);
                },
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

    public function getMetricsAttribute()
    {
        return Cache::remember("establishment_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $items = $this->items();
            $employers = $this->employers();
            $orders = $this->orders();

            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalItems = $items->count();
            $totalEmployers = $employers->count();
            $totalOrders = $orders->count();

            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();

            $totalRevenue = (clone $orders)->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $avgViewsPerUser = $uniqueUsers > 0 ? round($totalViews / $uniqueUsers, 2) : 0;
            $avgViewsPerItem = $totalItems > 0 ? round($totalViews / $totalItems, 2) : 0;
            $avgViewsPerEmployer = $totalEmployers > 0 ? round($totalViews / $totalEmployers, 2) : 0;

            $totalEmployerViews = \App\Models\Interaction::where('entity_type', 'Employer')
                ->whereIn('entity_id', $employers->pluck('id'))
                ->where('interaction_type', 'view')
                ->count();

            $avgEmployerViews = $totalEmployers > 0 ? round($totalEmployerViews / $totalEmployers, 2) : 0;

            $firstView = $views->min('created_at');
            if ($firstView && !($firstView instanceof \Carbon\Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

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

            $avgRevenuePerEmployer = $totalEmployers > 0 ? round($totalRevenue / $totalEmployers, 2) : 0;

            return [
                'total_items' => $totalItems,
                'total_employers' => $totalEmployers,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'avg_revenue_per_employer' => $avgRevenuePerEmployer,
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_user' => $avgViewsPerUser,
                'avg_views_per_item' => $avgViewsPerItem,
                'avg_views_per_employer' => $avgViewsPerEmployer,
                'avg_employer_views' => $avgEmployerViews,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,
                'engagement_score' => $engagementScore,
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
                // avatar aqui ainda � coluna antiga; pode ser migrado depois para files
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

    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments)
            ? json_decode($this->segments, true)
            : ($this->segments ?? []);

        if (empty($segmentsArray)) {
            return '<i>Nenhum seguimento atribu�do</i>';
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

            $totalOrders = $orders->count();

            $completedOrders = $orders->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = $orders->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = $orders->where('appointment_status', 'pending')->count();

            $totalRevenue = $orders->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

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

            $activeDays = $orders->pluck('created_at')->map(fn($d) => $d->toDateString())->unique()->count();

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
        $appId = $this->app_id ?? $this->establishment?->app_id ?? null;

        if (!$appId) {
            return collect();
        }

        return Cache::remember("{$this->entity_name}_{$this->id}_other_establishments", 120, function () use ($appId) {
            return \App\Models\Establishment::where('app_id', $appId)
                ->where('id', '!=', $this->id)
                ->with(['files' => fn($q) => $q->where('entity_name', 'establishment')])
                ->withCount([
                    'views as total_views' => fn($q) =>
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

    public function otherEmployers()
    {
        $appId = $this->app_id ?? $this->establishment?->app_id ?? null;
        $establishmentId = $this->establishment_id ?? null;

        if (!$appId) {
            return collect();
        }

        return Cache::remember("{$this->entity_name}_{$this->id}_other_employers", 120, function () use ($appId, $establishmentId) {
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
                    $files = $item->files ?? collect();
                    $image = $files->firstWhere('type', 'image')?->public_url
                        ?? $item->image;

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'price' => $item->price,
                        'type' => $item->type,
                        'image' => $image,
                        'total_views' => $item->total_views ?? 0,
                        'images' => [
                            'avatar' => $image,
                            'gallery' => $files
                                ->where('type', 'image')
                                ->pluck('public_url')
                                ->values(),
                        ],
                    ];
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
   public static function findForView(string $slug): self
{
    return self::where('slug', $slug)
        ->with([
            'files' => fn($q) => $q->where('entity_name', 'establishment')->orderBy('position'),

            'user' => function ($q) {
                $q->select('id', 'first_name', 'last_name', 'user_name', 'email', 'city', 'uf')
                    ->with([
                        'avatarFile:id,entity_id,entity_name,type,public_url',
                        'files',
                    ]);
            },

            'employers' => function ($q) {
                $q->with([
                    'user' => function ($uq) {
                        $uq->select('id', 'first_name', 'last_name', 'user_name', 'email', 'city', 'uf')
                            ->with([
                                'avatarFile:id,entity_id,entity_name,type,public_url',
                                'files',
                            ]);
                    },
                    'files' => fn($fq) =>
                        $fq->where('entity_name', 'employer')
                            ->orderBy('position'),
                ]);
            },

            'items' => function ($q) {
                $q->where('entity_name', 'establishment')
                    ->with([
                        'files' => fn($fq) =>
                            $fq->where('entity_name', 'item')
                                ->orderBy('position'),
                    ])
                    ->orderByDesc('updated_at');
            },

            'orders.client:id,first_name,last_name,user_name,avatar,email',
            'interactions.user:id,first_name,last_name,user_name,avatar,email',
        ])
        ->firstOrFail();
}


    public function toViewPayload(): array
{
    $estFiles = $this->files ?? collect();

    $itemsPayload = $this->items->map(function ($item) {
        $files = $item->files ?? collect();

        return [
            'id' => $item->id,
            'entity_id' => $item->entity_id,
            'type' => 'item',
            'name' => $item->name,
            'slug' => $item->slug,
            'price' => $item->price,
            'item_type' => $item->type,
            'files' => $files->map(function ($file) {
                return [
                    'id' => $file->id,
                    'type' => $file->type,
                    'public_url' => $file->public_url,
                ];
            })->values(),
        ];
    })->values();

    $employersPayload = $this->employers->map(function ($emp) {
        $empFiles = $emp->files ?? collect();
        $uFiles = $emp->user?->files ?? collect();

        $allFiles = $empFiles->merge($uFiles);

        return [
            'id' => $emp->id,
            'type' => 'employer',
            'name' => trim(($emp->user?->first_name ?? '') . ' ' . ($emp->user?->last_name ?? '')),
            'slug' => $emp->user?->user_name,
            'files' => $allFiles->map(function ($file) {
                return [
                    'id' => $file->id,
                    'type' => $file->type,
                    'public_url' => $file->public_url,
                ];
            })->values(),
        ];
    })->values();

    return [
        'establishment' => [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'slug' => $this->slug,
            'city' => $this->city,
            'uf' => $this->uf,
            'files' => $estFiles->map(function ($file) {
                return [
                    'id' => $file->id,
                    'type' => $file->type,
                    'public_url' => $file->public_url,
                ];
            })->values(),
        ],
        'items' => $itemsPayload,
        'employers' => $employersPayload,
        'metrics' => $this->metrics,
        'interaction_summary' => $this->interactionSummary(),
        'user_interactions' => $this->userInteractions(),
        'orders_summary' => $this->ordersSummary(),
        'completed_appointments' => $this->completedAppointments(),
        'other_establishments' => $this->otherEstablishments()->map(function ($est) {
            $estFiles = $est->files ?? collect();
            return [
                'id' => $est->id,
                'name' => $est->name,
                'slug' => $est->slug,
                'city' => $est->city,
                'category' => $est->category,
                'files' => $estFiles->map(function ($file) {
                    return [
                        'id' => $file->id,
                        'type' => $file->type,
                        'public_url' => $file->public_url,
                    ];
                })->values(),
                'total_views' => $est->total_views ?? 0,
            ];
        })->values(),
        'other_employers' => $this->otherEmployers()->map(function ($emp) {
            $allFiles = ($emp->files ?? collect())->merge($emp->user?->files ?? collect());

            return [
                'id' => $emp->id,
                'name' => trim(($emp->user?->first_name ?? '') . ' ' . ($emp->user?->last_name ?? '')),
                'user_name' => $emp->user?->user_name,
                'files' => $allFiles->map(function ($file) {
                    return [
                        'id' => $file->id,
                        'type' => $file->type,
                        'public_url' => $file->public_url,
                    ];
                })->values(),
                'total_views' => $emp->total_views ?? 0,
                'establishment' => [
                    'id' => $emp->establishment?->id,
                    'name' => $emp->establishment?->name,
                    'slug' => $emp->establishment?->slug,
                ],
            ];
        })->values(),
        'other_items' => $this->otherItems()->map(function ($item) {
            $files = $item->files ?? collect();
            return [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'price' => $item->price,
                'type' => $item->type,
                'files' => $files->map(function ($file) {
                    return [
                        'id' => $file->id,
                        'type' => $file->type,
                        'public_url' => $file->public_url,
                    ];
                })->values(),
                'total_views' => $item->total_views ?? 0,
            ];
        })->values(),
    ];
}



}
