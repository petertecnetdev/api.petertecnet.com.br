<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Item extends Model
{
    protected $table = 'items';

    protected $fillable = [
        'user_id',
        'app_id',
        'slug',
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
        'status' => 'boolean',
        'limited_by_user' => 'boolean',
        'is_featured' => 'boolean',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tags' => 'array',
        'availability_start' => 'datetime',
        'availability_end' => 'datetime',
        'expiration_date' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'metrics',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $base = Str::slug($model->name);
                $slug = $base;
                $i = 1;

                while (
                    self::where('slug', $slug)
                        ->where('app_id', $model->app_id)
                        ->exists()
                ) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }

                $model->slug = $slug;
            }
        });
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', 'item')
            ->orderBy('position');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Item');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'item_id');
    }

    public function views(): HasMany
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    public function likes(): HasMany
    {
        return $this->interactions()->where('interaction_type', 'like');
    }

    public function favorites(): HasMany
    {
        return $this->interactions()->where('interaction_type', 'favorite');
    }

    public function getImageUrlAttribute()
    {
        $files = $this->files;

        return
            $files->firstWhere('is_primary', true)?->public_url
            ?? $files->firstWhere('type', 'image')?->public_url
            ?? $files->first()?->public_url
            ?? $this->image;
    }

    public function getMetricsAttribute()
    {
        return Cache::remember("item_{$this->id}_metrics", 120, function () {
            $viewsQuery = $this->views();
            $ordersQuery = $this->orderItems();

            $views = $viewsQuery->count();
            $unique_users = $viewsQuery
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            $total_orders = $ordersQuery->count();

            $completed_orders = $ordersQuery
                ->whereHas('order', function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended']);
                })
                ->count();

            $cancelled_orders = $ordersQuery
                ->whereHas('order', function ($q) {
                    $q->whereIn('appointment_status', ['cancelled', 'rejected']);
                })
                ->count();

            return [
                'views' => $views,
                'unique_users' => $unique_users,
                'likes' => $this->likes()->count(),
                'favorites' => $this->favorites()->count(),
                'total_orders' => $total_orders,
                'completed_orders' => $completed_orders,
                'cancelled_orders' => $cancelled_orders,
            ];
        });
    }
}
