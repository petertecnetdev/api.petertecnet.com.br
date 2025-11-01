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

    protected $appends = [
        'items_interactions',
        'interaction_summary',
        'user_interactions',
        'other_establishments',
        'metrics',
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

    /* =======================
       MÉTRICAS E INTERAÇÕES
       ======================= */

    public function getMetricsAttribute()
    {
        return [
            'total_items'     => $this->items()->count(),
            'total_employers' => $this->employers()->count(),
            'total_views'     => $this->views()->count(),
            'unique_users'    => $this->views()->pluck('user_id')->unique()->count(),
        ];
    }

    public function getInteractionSummaryAttribute()
    {
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
    }

    public function getItemsInteractionsAttribute()
    {
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
    }

    public function getUserInteractionsAttribute()
    {
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
    }

    public function getOtherEstablishmentsAttribute()
    {
        return self::where('app_id', $this->app_id)
            ->where('id', '!=', $this->id)
            ->withCount(['views as total_views'])
            ->limit(6)
            ->get(['id', 'name', 'slug', 'logo', 'city', 'category']);
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
}
