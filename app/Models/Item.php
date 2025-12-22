<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

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

    public function employers()
    {
        return $this->hasMany(Employer::class, 'entity_id', 'entity_id')
            ->with('user');
    }

    public function entity()
    {
        return $this->morphTo(null, 'entity_name', 'entity_id');
    }


    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'entity_id', 'id');
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }



    public static function otherItems(int $limit = 20): Collection
    {
        // Pega itens ativos agrupados por estabelecimento
        $groups = Item::with('establishment')
            ->where('status', 'active')
            ->get()
            ->groupBy('entity_id');

        // Embaralha os grupos para não começar sempre pelo mesmo estabelecimento
        $groups = $groups->shuffle();

        $result = collect();

        while ($groups->isNotEmpty() && $result->count() < $limit) {
            foreach ($groups as $key => $group) {
                if ($group->isNotEmpty()) {
                    $result->push($group->shift()); // pega 1 item do grupo
                    if ($result->count() >= $limit)
                        break 2;
                } else {
                    $groups->forget($key); // remove grupo vazio
                }
            }
        }

        return $result;
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
            $likesQuery = $this->likes();
            $favoritesQuery = $this->favorites();

            $orderItems = \App\Models\OrderItem::where('item_id', $this->id);
            $orders = \App\Models\Order::whereIn('id', $orderItems->pluck('order_id'));

            $totalViews = $viewsQuery->count();
            $uniqueUsers = (clone $viewsQuery)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            $totalLikes = $likesQuery->count();
            $totalFavorites = $favoritesQuery->count();

            $totalOrders = $orders->count();
            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();

            $totalRevenue = (clone $orderItems)->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $firstView = $viewsQuery->min('created_at');
            if ($firstView && !($firstView instanceof \Carbon\Carbon)) {
                $firstView = \Carbon\Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);
            $avgViewsPerUser = $uniqueUsers > 0 ? round($totalViews / $uniqueUsers, 2) : 0;

            $conversionRate = $totalViews > 0
                ? round(($totalOrders / $totalViews) * 100, 2)
                : 0;

            $completionRate = $totalOrders > 0
                ? round(($completedOrders / $totalOrders) * 100, 2)
                : 0;

            $cancellationRate = $totalOrders > 0
                ? round(($cancelledOrders / $totalOrders) * 100, 2)
                : 0;

            $clientsCount = (clone $orders)
                ->selectRaw('client_id, COUNT(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
            $returnRate = $clientsCount->count() > 0
                ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
                : 0;

            // Trocar employer_id por attendant_id
            $employerStats = (clone $orders)
                ->selectRaw('attendant_id, COUNT(*) as total')
                ->whereNotNull('attendant_id')
                ->groupBy('attendant_id')
                ->orderByDesc('total')
                ->get();

            $topEmployer = $employerStats->first();
            if ($topEmployer) {
                $topEmployerData = [
                    'employer_id' => $topEmployer->attendant_id,
                    'total_orders' => $topEmployer->total,
                    'employer' => \App\Models\Employer::find($topEmployer->attendant_id),
                ];
            } else {
                $topEmployerData = null;
            }

            $engagementScore = round(
                ($uniqueUsers * 1.2) +
                ($totalViews * 0.3) +
                ($totalLikes * 0.5) +
                ($totalFavorites * 0.7) +
                ($completedOrders * 1.5),
                2
            );

            return [
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_user' => $avgViewsPerUser,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,

                'likes' => $totalLikes,
                'favorites' => $totalFavorites,

                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,

                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,

                'conversion_rate' => $conversionRate,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'return_rate' => $returnRate,

                'top_employer' => $topEmployerData,

                'engagement_score' => $engagementScore,
            ];
        });
    }


    public static function totalDurationForItems(array $items): int
    {
        $total = 0;

        foreach ($items as $itemData) {
            $item = self::find($itemData['item_id']);
            if ($item) {
                $quantity = $itemData['quantity'] ?? 1;
                $total += ($item->duration ?? 0) * $quantity;
            }
        }

        return $total;
    }
}
