<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    /* ===============================
       RELACIONAMENTOS DIRETOS
    ================================ */

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

    /* ===============================
       MÉTRICAS E RESUMOS (CACHEADOS)
    ================================ */

    public function getMetricsAttribute()
    {
        return Cache::remember("employer_{$this->id}_metrics", 120, function () {
            return [
                'total_orders' => $this->orders()->count(),
                'total_views' => $this->views()->count(),
                'unique_users' => $this->views()->pluck('user_id')->unique()->count(),
            ];
        });
    }

    public function interactionSummary()
    {
        return Cache::remember("employer_{$this->id}_summary", 120, function () {
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

    public function otherEmployers()
    {
        return Cache::remember("employer_{$this->id}_related", 120, function () {
            return self::where('establishment_id', $this->establishment_id)
                ->where('id', '!=', $this->id)
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->limit(6)
                ->get();
        });
    }

    public function ordersSummary()
    {
        return Cache::remember("employer_{$this->id}_orders_summary", 120, function () {
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
                    'completion_rate' => 0,
                    'cancellation_rate' => 0,
                    'top_client' => null,
                ];
            }

            $total = $orders->count();
            $completed = $orders->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelled = $orders->where('appointment_status', 'cancelled')->count();
            $pending = $orders->where('appointment_status', 'pending')->count();
            $revenue = $orders->sum('total_price');
            $avg = $total > 0 ? round($revenue / $total, 2) : 0;

            $topClient = $orders->groupBy('client_id')->map(function ($group) {
                $c = $group->first()->client;
                return [
                    'id' => $c?->id,
                    'name' => trim(($c?->first_name ?? '') . ' ' . ($c?->last_name ?? '')),
                    'user_name' => $c?->user_name,
                    'avatar' => $c?->avatar,
                    'total_orders' => $group->count(),
                ];
            })->sortByDesc('total_orders')->first();

            return [
                'total_orders' => $total,
                'completed_orders' => $completed,
                'cancelled_orders' => $cancelled,
                'pending_orders' => $pending,
                'total_revenue' => $revenue,
                'average_ticket' => $avg,
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
                'cancellation_rate' => $total > 0 ? round(($cancelled / $total) * 100, 2) : 0,
                'top_client' => $topClient,
            ];
        });
    }
}
