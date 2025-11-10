<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        'tags' => 'array',
        'availability_start' => 'datetime',
        'availability_end' => 'datetime',
        'expiration_date' => 'datetime',
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

    /* ===============================
       RELACIONAMENTOS DIRETOS
    ================================ */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function app()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'entity_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'item_id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    /* ===============================
       REGRAS DE NEGÓCIO
    ================================ */

    public static function totalDurationForItems(array $items)
    {
        $total = 0;
        foreach ($items as $entry) {
            $ids = is_array($entry['item_id']) ? $entry['item_id'] : [$entry['item_id']];
            foreach ($ids as $id) {
                $item = self::find($id);
                if ($item) {
                    $total += $item->duration ?? 0;
                }
            }
        }
        return $total;
    }

    public static function invalidForEntity(array $itemIds, $entityName, $entityId)
    {
        return self::whereIn('id', $itemIds)
            ->where(function ($q) use ($entityName, $entityId) {
                $q->where('entity_name', '!=', $entityName)
                    ->orWhere('entity_id', '!=', $entityId);
            })
            ->pluck('name')
            ->toArray();
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

    public function getMetricsAttribute()
    {
        return Cache::remember("item_{$this->id}_metrics", 120, function () {
            return [
                'total_views' => $this->views()->count(),
                'unique_users' => $this->views()->pluck('user_id')->unique()->count(),
                'appointments' => $this->appointmentsCount(),
                'is_available' => $this->isAvailable(),
                'price' => $this->price,
                'discount' => $this->discount,
                'stock' => $this->stock,
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

            $mostActive = $views->groupBy('user_id')->map(function ($group) {
                $u = $group->first()->user;
                return [
                    'user_id' => $u?->id,
                    'user_name' => $u?->user_name,
                    'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                    'avatar' => $u?->avatar,
                    'total_views' => $group->count(),
                ];
            })->sortByDesc('total_views')->first();

            $lastView = $views->sortByDesc('created_at')->first()?->user;

            return [
                'total_views' => $views->count(),
                'unique_users' => $views->pluck('user_id')->unique()->count(),
                'most_active_user' => $mostActive,
                'last_view_user' => $lastView ? [
                    'user_id' => $lastView->id,
                    'user_name' => $lastView->user_name,
                    'name' => trim(($lastView->first_name ?? '') . ' ' . ($lastView->last_name ?? '')),
                    'avatar' => $lastView->avatar,
                    'email' => $lastView->email,
                ] : null,
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

    /* ===============================
       DISPONIBILIDADE E AGENDAMENTOS
    ================================ */

    public function isAvailable(): bool
    {
        return (bool) $this->status
            && ($this->stock > 0)
            && (is_null($this->availability_start) || $this->availability_start->lte(now()))
            && (is_null($this->availability_end) || $this->availability_end->gte(now()));
    }

    public function appointmentsCount(): int
    {
        return Cache::remember("item_{$this->id}_appointments", 120, function () {
            return DB::table('order_items')
                ->where('item_id', $this->id)
                ->count();
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
                    'return_rate' => 0,
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
                'cancellation_rate' => $totalOrders > 0 ? round(($cancelled / $totalOrders) * 100, 2) : 0,
                'completion_rate' => $totalOrders > 0 ? round(($completed / $totalOrders) * 100, 2) : 0,
                'pending_rate' => $totalOrders > 0 ? round(($pending / $totalOrders) * 100, 2) : 0,
                'efficiency_rate' => ($completed + $cancelled) > 0 ? round(($completed / ($completed + $cancelled)) * 100, 2) : 0,
                'return_rate' => 0,
            ];
        });
    }

    /* ===============================
       ITENS RELACIONADOS
    ================================ */

    public function relatedItems($limit = 6)
    {
        return Cache::remember("item_{$this->id}_related", 120, function () use ($limit) {
            return self::where('entity_name', 'establishment')
                ->where('entity_id', $this->entity_id)
                ->where('id', '!=', $this->id)
                ->where('status', 1)
                ->limit($limit)
                ->get(['id', 'name', 'slug', 'price', 'image', 'category', 'type']);
        });
    }

    public function otherItems()
    {
        return Cache::remember("item_{$this->id}_related_all", 120, function () {
            return self::where('app_id', $this->app_id)
                ->where('id', '!=', $this->id)
                ->with(['entity:id,name,slug,logo,background,app_id'])
                ->withCount(['views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                }])
                ->limit(6)
                ->get([
                    'id',
                    'entity_id',
                    'name',
                    'slug',
                    'price',
                    'type',
                    'image',
                ]);
        });
    }

    /* ===============================
       PROFISSIONAIS ASSOCIADOS (SERVIÇOS)
    ================================ */

    public function associatedEmployers()
    {
        $establishment = $this->establishment;

        if (!$establishment) {
            return collect();
        }

        if (Str::contains(Str::lower($this->type), 'serv') || $this->type === 'serviço') {
            return $establishment->employers()
                ->with(['user:id,first_name,last_name,avatar,user_name,email'])
                ->get();
        }

        return collect();
    }

    /* ===============================
       WHATSAPP DO ESTABELECIMENTO
    ================================ */

    public function whatsappLink()
    {
        $establishment = $this->establishment;

        if (!$establishment || !$establishment->phone) {
            return null;
        }

        return 'https://wa.me/55' . preg_replace('/\D/', '', $establishment->phone)
            . '?text=' . urlencode("Olá! Gostaria de saber mais sobre o item \"{$this->name}\".");
    }

    /* ===============================
       VERSÃO LEVE PARA LISTAGENS
    ================================ */

    public static function withLightItems($id)
    {
        return self::where('id', $id)
            ->withCount(['views as total_views'])
            ->first();
    }
}
