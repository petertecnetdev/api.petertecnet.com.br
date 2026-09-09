<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'order_number',
        'order_datetime',
        'created_by',
        'attendant_id',
        'client_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_cpf',
        'access_code',
        'origin',
        'fulfillment',
        'payment_status',
        'payment_method',
        'total_price',
        'total_duration',
        'status',
        'notes',
        'type',
        'appointment_status',
        'confirmed_by',
        'cancelled_by',
        'cancelled_reason',
        'attended_at',
    ];

    protected $casts = [
        'order_datetime' => 'datetime',
        'attended_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['attendant_user', 'client_user'];

    /* ===============================
       RELACIONAMENTOS DIRETOS
    ================================ */

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(Employer::class, 'attendant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    public function getEstablishmentAttribute()
    {
        return $this->entity;
    }

    /* ===============================
       ATTENDANT USER (EMPLOYER -> USER)
    ================================ */

    public function getAttendantUserAttribute()
    {
        if (empty($this->attendant_id)) {
            return null;
        }

        $this->loadMissing([
            'attendant' => function ($q) {
                $q->select([
                    'id',
                    'user_id',
                    'establishment_id',
                    'role',
                    'permissions',
                    'created_by',
                    'updated_by',
                    'created_at',
                    'updated_at',
                ])->with([
                            'user:id,first_name,last_name,user_name,avatar,email',
                        ]);
            },
        ]);

        return $this->attendant?->user;
    }

    public function getClientUserAttribute()
    {
        if (empty($this->client_id)) {
            return null;
        }

        $this->loadMissing([
            'client' => function ($q) {
                $q->select([
                    'id',
                    'first_name',
                    'last_name',
                    'user_name',
                    'email',
                    'avatar',
                ])->with([
                            'avatarFile:id,entity_id,path',
                        ]);
            },
        ]);

        $user = $this->client;

        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_name' => $user->user_name,
            'email' => $user->email,
            'avatar' => $user->avatarFile?->path ?? $user->avatar,
        ];
    }


    /* ===============================
       M�TODOS EST�TICOS AUXILIARES
    ================================ */

    public static function hasScheduleConflict($attendantId, $start, $end): bool
    {
        return self::where('attendant_id', $attendantId)
            ->where('type', 'appointment')
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('order_datetime', [$start, $end])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('order_datetime', '<', $start)
                            ->whereRaw('DATE_ADD(order_datetime, INTERVAL total_duration MINUTE) > ?', [$start]);
                    });
            })
            ->exists();
    }

    public static function nextOrderNumber($appId): string
    {
        // Order creation flows run inside a database transaction. Locking the
        // application row serializes number allocation across establishments
        // of the same app so concurrent orders cannot receive the same number.
        if (DB::transactionLevel() > 0) {
            DB::table('applications')
                ->where('id', $appId)
                ->lockForUpdate()
                ->first();
        }

        $last = self::where('app_id', $appId)->max('order_number') ?: 0;
        return str_pad($last + 1, 3, '0', STR_PAD_LEFT);
    }

    public static function generateAccessCode(): string
    {
        return str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public static function createOrder($data, $user, $orderDate, $totalDuration, $isScheduled, $type, $appointmentStatus): self
    {
        return self::create([
            'app_id' => $data['app_id'],
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
            'order_number' => self::nextOrderNumber($data['app_id']),
            'order_datetime' => $orderDate,
            'created_by' => $user->id,
            'client_id' => $data['client_id'] ?? null,
            'attendant_id' => $data['attendant_id'],
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_cpf' => $data['customer_cpf'] ?? null,
            'access_code' => self::generateAccessCode(),
            'origin' => $data['origin'],
            'fulfillment' => $data['fulfillment'],
            'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'],
            'status' => $isScheduled ? 'scheduled' : 'completed',
            'notes' => $data['notes'] ?? null,
            'type' => $type,
            'appointment_status' => $appointmentStatus,
            'total_price' => 0,
            'total_duration' => $totalDuration,
        ]);
    }

    public function attachItems(array $items)
    {
        foreach ($items as $entry) {
            $itemId = (int) $entry['item_id'];
            $quantity = (int) ($entry['quantity'] ?? 1);
            $additions = $entry['additions'] ?? [];
            $removals = $entry['removals'] ?? [];

            $item = $this->resolveContextItem($itemId, 'item');

            $unitPrice = (float) $item->price;
            $subtotal = $unitPrice * $quantity;

            $orderItem = $this->items()->create([
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ]);

            foreach ($additions as $add) {
                $modifier = $this->resolveContextItem((int) $add['id'], 'adicional');

                $orderItem->modifiers()->create([
                    'modifier_id' => $modifier->id,
                    'quantity' => max(1, (int) ($add['quantity'] ?? 1)),
                    'type' => 'addition',
                ]);
            }

            foreach ($removals as $remId) {
                $modifier = $this->resolveContextItem((int) $remId, 'remoção');

                $orderItem->modifiers()->create([
                    'modifier_id' => $modifier->id,
                    'type' => 'removal',
                ]);
            }
        }
    }

    private function resolveContextItem(int $itemId, string $label): Item
    {
        $item = Item::query()
            ->forApplication((int) $this->app_id)
            ->find($itemId);

        if (!$item) {
            throw \Illuminate\Validation\ValidationException::withMessages(["items" => "O {$label} informado não está disponível para esta aplicação."]);
        }

        if (
            strtolower(trim((string) $item->entity_name)) !== strtolower(trim((string) $this->entity_name)) ||
            (int) $item->entity_id !== (int) $this->entity_id
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages(["items" => "O {$label} '{$item->name}' não pertence ao estabelecimento desta ordem."]);
        }

        return $item;
    }

    /* ===============================
       INTERA��ES E M�TRICAS
    ================================ */

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Order');
    }

    public function views(): HasMany
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    public function latestViews(): HasMany
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

    public function totalViewsCount(): int
    {
        return $this->views()->count();
    }

    public function itemsViews(): int
    {
        return $this->items()
            ->withCount([
                'interactions as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                }
            ])
            ->get()
            ->sum('total_views');
    }

    public function metrics(): array
    {
        return [
            'total_items' => $this->items()->count(),
            'total_views' => $this->totalViewsCount(),
            'unique_viewers' => $this->uniqueViewers()->count(),
            'items_views' => $this->itemsViews(),
            'most_active_viewer' => $this->mostActiveViewer(),
        ];
    }

    public function fullInteractionsSummary(): array
    {
        return [
            'order' => [
                'order_number' => $this->order_number,
                'total_views' => $this->totalViewsCount(),
                'unique_users' => $this->uniqueViewers()->count(),
                'most_active_user' => $this->mostActiveViewer()?->user ?? null,
            ],
            'items' => $this->items()->withCount([
                'interactions as views' => function ($q) {
                    $q->where('interaction_type', 'view');
                }
            ])->get(['id', 'item_id', 'quantity', 'views']),
        ];
    }

    /* ===============================
       STATUS E UTILIT�RIOS
    ================================ */

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending' || $this->status === 'pending';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isConfirmed(): bool
    {
        return $this->appointment_status === 'confirmed' || $this->status === 'confirmed';
    }

    public function isCompleted(): bool
    {
        return $this->appointment_status === 'completed' || $this->status === 'completed';
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->appointment_status === 'awaiting_confirmation';
    }

    public function isRefunded(): bool
    {
        return $this->payment_status === 'refunded';
    }

    public function establishment()
{
    return $this->belongsTo(Establishment::class, 'entity_id')
        ->where('entity_name', 'establishment');
}
}
