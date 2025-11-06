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
                $model->slug = Str::slug($model->name);
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
        return $this->belongsTo(Establishment::class, 'entity_id')
            ->where('entity_name', 'establishment');
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

    public function getMetricsAttribute()
    {
        return Cache::remember("item_{$this->id}_metrics", 120, function () {
            return [
                'total_views'   => $this->views()->count(),
                'unique_users'  => $this->views()->pluck('user_id')->unique()->count(),
                'appointments'  => $this->appointmentsCount(),
                'is_available'  => $this->isAvailable(),
                'price'         => $this->price,
                'discount'      => $this->discount,
                'stock'         => $this->stock,
            ];
        });
    }

    public function interactionSummary()
    {
        return Cache::remember("item_{$this->id}_summary", 120, function () {
            $views = $this->views()
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->get();

            if ($views->isEmpty()) {
                return [
                    'total_views'      => 0,
                    'unique_users'     => 0,
                    'most_active_user' => null,
                    'last_view_user'   => null,
                ];
            }

            $mostActive = $views->groupBy('user_id')->map(function ($group) {
                $u = $group->first()->user;
                return [
                    'user_id'     => $u?->id,
                    'user_name'   => $u?->user_name,
                    'name'        => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                    'avatar'      => $u?->avatar,
                    'total_views' => $group->count(),
                ];
            })->sortByDesc('total_views')->first();

            $lastView = $views->sortByDesc('created_at')->first()?->user;

            return [
                'total_views'      => $views->count(),
                'unique_users'     => $views->pluck('user_id')->unique()->count(),
                'most_active_user' => $mostActive,
                'last_view_user'   => $lastView ? [
                    'user_id'   => $lastView->id,
                    'user_name' => $lastView->user_name,
                    'name'      => trim(($lastView->first_name ?? '') . ' ' . ($lastView->last_name ?? '')),
                    'avatar'    => $lastView->avatar,
                    'email'     => $lastView->email,
                ] : null,
            ];
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
       PRÓXIMOS HORÁRIOS DISPONÍVEIS
    ================================ */

    public function nextSlots()
    {
        if (!method_exists($this, 'nextAvailableSlots')) {
            return [];
        }

        try {
            return $this->nextAvailableSlots();
        } catch (\Exception $e) {
            \Log::warning('[Item::nextSlots] Erro ao buscar horários', [
                'item_id' => $this->id,
                'erro'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ===============================
       LINK WHATSAPP DO ESTABELECIMENTO
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
       VARIANTE REDUZIDA (para listagens leves)
    ================================ */

    public static function withLightItems($id)
    {
        return self::where('id', $id)
            ->with(['views' => function ($q) {
                $q->select('entity_id')->withCount('id as total_views');
            }])
            ->first();
    }
}
