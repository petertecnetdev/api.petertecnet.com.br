<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class Establishment extends Model
{
    protected $fillable = [
        'name', 'fantasy', 'slug', 'cnpj', 'type', 'category',
        'phone', 'email', 'description', 'additional_info',
        'city', 'location', 'cep', 'address',
        'user_id', 'updated_by', 'created_by', 'logo', 'background',
        'is_featured', 'is_published', 'is_approved', 'is_cancelled',
        'website_url', 'facebook_url', 'instagram_url',
        'twitter_url', 'youtube_url', 'segments', 'app_id'
    ];

    protected $casts = [
        'segments'     => 'json',
        'is_featured'  => 'boolean',
        'is_published' => 'boolean',
        'is_approved'  => 'boolean',
        'is_cancelled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->fantasy ?? $model->name);
            }
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
            ->where('entity_name', 'establishment');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
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

    public function itemsViews()
    {
        return $this->items()
            ->withCount(['interactions as total_views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])
            ->get()
            ->sum('total_views');
    }

    public function employersViews()
    {
        return $this->employers()
            ->withCount(['interactions as total_views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])
            ->get()
            ->sum('total_views');
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
            'total_items'         => $this->items()->count(),
            'total_employers'     => $this->employers()->count(),
            'total_orders'        => $this->orders()->count(),
            'total_views'         => $this->totalViewsCount(),
            'unique_viewers'      => $this->uniqueViewers()->count(),
            'items_views'         => $this->itemsViews(),
            'employers_views'     => $this->employersViews(),
            'orders_views'        => $this->ordersViews(),
            'most_active_viewer'  => $this->mostActiveViewer(),
        ];
    }

    public function fullInteractionsSummary()
    {
        $data = [
            'establishment' => [
                'total_views' => $this->totalViewsCount(),
                'unique_users' => $this->uniqueViewers()->count(),
                'most_active_user' => $this->mostActiveViewer()?->user ?? null,
            ],
            'items' => $this->items()->withCount(['interactions as views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])->get(['id', 'name', 'slug', 'price', 'views']),
            'employers' => $this->employers()->withCount(['interactions as views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])->get(['id', 'user_id', 'views']),
            'orders' => $this->orders()->withCount(['interactions as views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])->get(['id', 'order_number', 'views']),
        ];

        return $data;
    }

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
}
