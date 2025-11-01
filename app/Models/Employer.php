<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    /* ===============================
       INTERAÇÕES E MÉTRICAS
    ================================ */

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Employer');
    }

    public function views()
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    public function latestViews()
    {
        return $this->views()->latest()->limit(10);
    }

    public function uniqueViewers()
    {
        return $this->views()
            ->select('user_id')
            ->distinct()
            ->with('user:id,first_name,last_name,user_name,avatar,email');
    }

    public function mostActiveViewer()
    {
        return $this->views()
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->with('user:id,first_name,last_name,user_name,avatar,email')
            ->first();
    }

    public function totalViewsCount()
    {
        return $this->views()->count();
    }

    public function ordersViews()
    {
        return $this->orders()
            ->withCount(['interactions as total_views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])
            ->get()
            ->sum('total_views');
    }

    public function metrics()
    {
        return [
            'total_orders'       => $this->orders()->count(),
            'total_views'        => $this->totalViewsCount(),
            'unique_viewers'     => $this->uniqueViewers()->count(),
            'orders_views'       => $this->ordersViews(),
            'most_active_viewer' => $this->mostActiveViewer(),
        ];
    }

    public function fullInteractionsSummary()
    {
        $data = [
            'employer' => [
                'total_views' => $this->totalViewsCount(),
                'unique_users' => $this->uniqueViewers()->count(),
                'most_active_user' => $this->mostActiveViewer()?->user ?? null,
            ],
            'orders' => $this->orders()->withCount(['interactions as views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])->get(['id', 'order_number', 'views']),
        ];

        return $data;
    }
}
