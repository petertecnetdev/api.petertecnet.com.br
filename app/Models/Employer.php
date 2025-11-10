<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class Employer extends Model
{
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

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->slug) && $model->user) {
                $model->slug = Str::slug($model->user->user_name ?? $model->user->first_name ?? 'colaborador-' . $model->id);
            }
        });
    }

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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
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
       RELACIONAMENTOS DERIVADOS
       ========================== */

    public function establishmentItems()
    {
        return $this->hasManyThrough(Item::class, Establishment::class, 'id', 'entity_id', 'establishment_id', 'id')
            ->where('items.entity_name', 'establishment');
    }

    public function establishmentOrders()
    {
        return $this->hasManyThrough(Order::class, Establishment::class, 'id', 'entity_id', 'establishment_id', 'id')
            ->where('orders.entity_name', 'establishment');
    }

    /* ==========================
       MÉTRICAS E INTERAÇÕES
       ========================== */

    public function getMetricsAttribute()
    {
        return Cache::remember("employer_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $orders = $this->orders();
            $establishment = $this->establishment;
            $estOrders = $establishment ? $establishment->orders() : collect([]);
            $estViews = $establishment ? $establishment->views() : collect([]);

            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalOrders = $orders->count();

            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();
            $attendedOrders = (clone $orders)->where('appointment_status', 'attended')->count();

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

            $establishmentMetrics = $establishment ? $establishment->metrics : [];
            $totalEstablishmentViews = $estViews ? $estViews->count() : 0;
            $totalEstablishmentOrders = $estOrders ? $estOrders->count() : 0;

            return [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'attended_orders' => $attendedOrders,
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
                'establishment_views' => $totalEstablishmentViews,
                'establishment_orders' => $totalEstablishmentOrders,
                'establishment_metrics' => $establishmentMetrics,
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

    public function userInteractions()
    {
        return Cache::remember("employer_{$this->id}_user_interactions", 120, function () {
            $views = Interaction::where('entity_type', 'Employer')
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

    public function establishmentInteractions()
    {
        return Cache::remember("employer_{$this->id}_establishment_interactions", 120, function () {
            $establishment = $this->establishment;
            if (!$establishment)
                return [];

            $views = $establishment->views()->with('user:id,first_name,last_name,user_name,avatar,email')->get();

            return [
                'total_views' => $views->count(),
                'unique_users' => $views->pluck('user_id')->unique()->count(),
                'most_active_user' => $views->groupBy('user_id')->map(function ($g) {
                    $u = $g->first()->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'avatar' => $u?->avatar,
                        'total_views' => $g->count(),
                    ];
                })->sortByDesc('total_views')->first(),
            ];
        });
    }

    public function relatedEmployers()
    {
        return Cache::remember("employer_{$this->id}_related", 120, function () {
            return self::where('establishment_id', $this->establishment_id)
                ->where('id', '!=', $this->id)
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->limit(6)
                ->get();
        });
    }

    public function itemsInteractions()
    {
        return Cache::remember("employer_{$this->id}_items_interactions", 120, function () {
            $items = $this->establishmentItems()->get();
            return $items->map(function ($item) {
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
                    'name' => $item->name,
                    'total_views' => $views->count(),
                    'unique_users' => $views->pluck('user_id')->unique()->count(),
                    'most_active_user' => $mostActive,
                ];
            });
        });
    }

    /* ==========================
       MÉTODOS AUXILIARES
       ========================== */

    public static function findOrFallbackByUserName($user_name)
    {
        $employer = self::whereHas('user', fn($q) => $q->where('user_name', $user_name))
            ->with(['user', 'establishment'])
            ->first();

        if ($employer) {
            return $employer;
        }

        $user = User::where('user_name', $user_name)->first();
        if (!$user) {
            return null;
        }

        return self::where('user_id', $user->id)
            ->with(['establishment'])
            ->withCount('interactions')
            ->orderByDesc('interactions_count')
            ->first();
    }

    public function refreshViewMetrics($viewer = null)
    {
        Interaction::registerView($this, $viewer);
        Cache::forget("employer_{$this->id}_metrics");
        Cache::forget("employer_{$this->id}_summary");
        Cache::forget("employer_{$this->id}_user_interactions");
        Cache::forget("employer_{$this->id}_items_interactions");
        Cache::forget("employer_{$this->id}_related");
        Cache::forget("employer_{$this->id}_establishment_interactions");
    }

    public function toRichArray()
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'permissions' => $this->permissions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $this->user ? $this->user->toArray() : null,
            'establishment' => $this->establishment ? $this->establishment->toArray() : null,
            'metrics' => $this->metrics,
            'interaction_summary' => $this->interactionSummary(),
            'user_interactions' => $this->userInteractions(),
            'establishment_interactions' => $this->establishmentInteractions(),
            'items_interactions' => $this->itemsInteractions(),
            'related_employers' => $this->relatedEmployers(),
        ];
    }
    public static function validateEmployer($attendantId, $entityId)
{
    return self::with('user')
        ->where('id', $attendantId)
        ->where('establishment_id', $entityId)
        ->first();
}

}
