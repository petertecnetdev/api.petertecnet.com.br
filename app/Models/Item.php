<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Relations\BelongsTo;



class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'slug',
        'app_id',
        'name',
        'type',
        'sku',
        'description',
        'duration',
        'price',
        'stock',
        'status',
        'limited_by_user',
        'category',
        'subcategory',
        'brand',
        'availability_start',
        'availability_end',
        'image',
        'is_featured',
        'entity_id',
        'entity_name',
        'tags',
        'discount',
        'expiration_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags'               => 'array',
        'availability_start' => 'datetime',
        'availability_end'   => 'datetime',
        'expiration_date'    => 'datetime',
    ];

    /* ===============================
       RELACIONAMENTOS DIRETOS
    ================================ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'entity_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'item_id');
    }

    /* ===============================
       INTERAÇÕES E MÉTRICAS
    ================================ */

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Item');
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

    public function orderViews()
    {
        return $this->orderItems()
            ->withCount(['interactions as total_views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])
            ->get()
            ->sum('total_views');
    }

    public function metrics()
    {
        return [
            'total_orders'       => $this->orderItems()->count(),
            'total_views'        => $this->totalViewsCount(),
            'unique_viewers'     => $this->uniqueViewers()->count(),
            'order_views'        => $this->orderViews(),
            'most_active_viewer' => $this->mostActiveViewer(),
        ];
    }

    public function fullInteractionsSummary()
    {
        $data = [
            'item' => [
                'id' => $this->id,
                'name' => $this->name,
                'total_views' => $this->totalViewsCount(),
                'unique_users' => $this->uniqueViewers()->count(),
                'most_active_user' => $this->mostActiveViewer()?->user ?? null,
            ],
            'orders' => $this->orderItems()->withCount(['interactions as views' => function ($q) {
                $q->where('interaction_type', 'view');
            }])->get(['id', 'order_id', 'views']),
        ];

        return $data;
    }

    /* ===============================
       DISPONIBILIDADE
    ================================ */

    public function isAvailable(): bool
    {
        return (bool) $this->status
            && ($this->stock > 0)
            && (is_null($this->availability_start) || $this->availability_start->lte(now()))
            && (is_null($this->availability_end)   || $this->availability_end->gte(now()));
    }
}
