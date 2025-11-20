<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Traits\HandlesImages;
use App\Traits\HasFiles;

class Item extends Model
{
    use HasFiles;
    use HandlesImages;
    protected $fillable = [
        'user_id',
        'app_id',
        'entity_id',
        'entity_name',
        'name',
        'slug',
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
        'tags',
        'discount',
        'expiration_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'availability_start' => 'datetime',
        'availability_end' => 'datetime',
        'expiration_date' => 'datetime',
        'is_featured' => 'boolean',
    ];

    protected $appends = ['metrics'];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->slug)) {
                $base = Str::slug($model->name);
                $slug = $base;
                $count = 1;
                while (self::where('slug', $slug)->where('id', '!=', $model->id)->exists()) {
                    $slug = "{$base}-{$count}";
                    $count++;
                }
                $model->slug = $slug;
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

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'entity_id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'item_id');
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Item');
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
        return Cache::remember("item_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $orders = $this->orderItems();

            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');

            $totalOrders = $orders->count();
            $completedOrders = (clone $orders)
                ->whereHas('order', fn($q) => $q->whereIn('appointment_status', ['confirmed', 'attended']))
                ->count();
            $cancelledOrders = (clone $orders)
                ->whereHas('order', fn($q) => $q->whereIn('appointment_status', ['cancelled', 'rejected']))
                ->count();
            $pendingOrders = (clone $orders)
                ->whereHas('order', fn($q) => $q->where('appointment_status', 'pending'))
                ->count();

            $totalRevenue = (clone $orders)->with('order')->get()->sum(fn($oi) => $oi->order?->total_price ?? 0);
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

            $firstView = $views->min('created_at');
            if ($firstView && !($firstView instanceof Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);

            $clientsCount = (clone $orders)
                ->whereHas('order', fn($q) => $q->whereNotNull('client_id'))
                ->selectRaw('order_id')
                ->count();

            $engagementScore = round(
                ($uniqueUsers * 1.5) +
                ($totalViews * 0.2) +
                ($completedOrders * 1.2),
                2
            );

            return [
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'pending_rate' => $pendingRate,
                'efficiency_rate' => $efficiencyRate,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,
                'engagement_score' => $engagementScore,
            ];
        });
    }

    public function interactionSummary()
    {
        return Cache::remember("item_{$this->id}_summary", 120, function () {
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

    public function ordersSummary()
    {
        return Cache::remember("item_{$this->id}_orders_summary", 120, function () {
            $orders = \App\Models\OrderItem::where('item_id', $this->id)
                ->with(['order.client:id,first_name,last_name,user_name,avatar,email'])
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
                ];
            }

            $totalOrders = $orders->count();
            $completed = $orders->where('order.appointment_status', 'attended')->count();
            $cancelled = $orders->where('order.appointment_status', 'cancelled')->count();
            $pending = $orders->where('order.appointment_status', 'pending')->count();
            $totalRevenue = $orders->sum(fn($oi) => $oi->order?->total_price ?? 0);
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            return [
                'total_orders' => $totalOrders,
                'completed_orders' => $completed,
                'cancelled_orders' => $cancelled,
                'pending_orders' => $pending,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'completion_rate' => $totalOrders > 0 ? round(($completed / $totalOrders) * 100, 2) : 0,
                'cancellation_rate' => $totalOrders > 0 ? round(($cancelled / $totalOrders) * 100, 2) : 0,
                'pending_rate' => $totalOrders > 0 ? round(($pending / $totalOrders) * 100, 2) : 0,
                'efficiency_rate' => ($completed + $cancelled) > 0
                    ? round(($completed / ($completed + $cancelled)) * 100, 2)
                    : 0,
            ];
        });
    }

    public function topEmployer()
    {
        $itemId = $this->id;

        return Cache::remember("item_{$itemId}_top_employer", 120, function () use ($itemId) {
            $top = \App\Models\Order::whereHas('items', function ($q) use ($itemId) {
                $q->where('item_id', $itemId);
            })
                ->where('appointment_status', 'attended')
                ->whereNotNull('attendant_id')
                ->selectRaw('attendant_id, COUNT(*) as total_completed')
                ->groupBy('attendant_id')
                ->orderByDesc('total_completed')
                ->first();

            if (!$top || !$top->attendant_id) {
                return null;
            }

            $employer = \App\Models\Employer::with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'establishment:id,name,slug,logo,background,city',
            ])->find($top->attendant_id);

            if (!$employer) {
                return null;
            }

            $u = $employer->user;
            $e = $employer->establishment;

            return [
                'id' => $employer->id,
                'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                'user_name' => $u?->user_name,
                'avatar' => $u?->avatar,
                'role' => $employer->role,
                'establishment' => $e?->name,
                'establishment_slug' => $e?->slug,
                'establishment_city' => $e?->city,
                'total_completed' => (int) $top->total_completed,
            ];
        });
    }

    public function userInteractions()
    {
        return Cache::remember("item_{$this->id}_user_interactions", 120, function () {
            $views = \App\Models\Interaction::where('entity_type', 'Item')
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
    /* ================================
       OTHERS (Item)
       Usando HasFiles e tabela files
    ================================ */

    /**
     * Retorna outros itens do mesmo app, com imagem da tabela files.
     */
    /* ============================================================================
       OTHERS — PADRÃO PARA Establishment, Employer e Item
       ============================================================================
    */

    /**
     * Outros estabelecimentos do mesmo app.
     */
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

    /**
     * Outros colaboradores (employers) do mesmo app.
     */
    public function otherEmployers()
    {
        $appId = $this->app_id ?? $this->establishment?->app_id ?? null;
        $establishmentId = $this->establishment_id ?? null;

        if (!$appId) {
            return collect();
        }

        return Cache::remember("{$this->entity_name}_{$this->id}_other_employers", 120, function () use ($appId, $establishmentId) {

            // Estabelecimentos do mesmo app
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

                    // 🔥 EXATAMENTE IGUAL AO EMPLOYERCONTROLLER::HOME
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

                        // Mesmo formato da home
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


    /**
     * Outros itens do mesmo app.
     */
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

                    $image = $item->files->firstWhere('type', 'image')?->public_url
                        ?? $item->image;

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'price' => $item->price,
                        'type' => $item->type,
                        'image' => $image,
                        'total_views' => $item->total_views ?? 0,
                    ];
                });
        });
    }



    public static function totalDurationForItems(array $items): int
    {
        $total = 0;

        foreach ($items as $entry) {
            $ids = is_array($entry['item_id']) ? $entry['item_id'] : [$entry['item_id']];
            $quantity = isset($entry['quantity']) ? (int) $entry['quantity'] : 1;

            foreach ($ids as $id) {
                $item = self::find($id);
                if ($item && isset($item->duration)) {
                    $total += (int) $item->duration * $quantity;
                }
            }
        }

        return $total;
    }

   public static function invalidForEntity(array $itemIds, string $entityName, int $entityId): array
{
    return self::whereIn('id', $itemIds)
        ->where(function ($q) use ($entityName, $entityId) {
            $q->where('entity_name', '!=', $entityName)
              ->orWhere('entity_id', '!=', $entityId);
        })
        ->pluck('id')
        ->toArray();
}

    public function fillFromRequest($request)
    {
        $this->fill([
            'name' => $request->input('name', $this->name),
            'type' => $request->input('type', $this->type),
            'price' => $request->input('price', $this->price),
            'stock' => $request->input('stock', $this->stock),
            'status' => (int) $request->input('status', $this->status),
            'description' => $request->input('description', $this->description),
            'category' => $request->input('category', $this->category),
            'subcategory' => $request->input('subcategory', $this->subcategory),
            'brand' => $request->input('brand', $this->brand),
            'availability_start' => $request->input('availability_start', $this->availability_start),
            'availability_end' => $request->input('availability_end', $this->availability_end),
            'is_featured' => (int) $request->input('is_featured', $this->is_featured),
            'discount' => $request->input('discount', $this->discount),
            'expiration_date' => $request->input('expiration_date', $this->expiration_date),
            'limited_by_user' => $request->input('limited_by_user', $this->limited_by_user),
            'notes' => $request->input('notes', $this->notes),
            'duration' => $request->input('duration', $this->duration),
        ]);

        return $this;
    }

    public function removeImageIfRequested($request)
    {
        if ((int) $request->input('remove_image') !== 1) {
            return $this;
        }

        $this->deleteImage($this->image);

        $this->image = null;
        $this->save();

        return $this;
    }

    public function uploadNewImageIfProvided($request)
    {
        if (!$request->hasFile('image')) {
            return $this;
        }

        $this->deleteImage($this->image);

        $this->image = $this->uploadImage($request->file('image'));
        $this->save();

        return $this;
    }

}
