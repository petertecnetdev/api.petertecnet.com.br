<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
        'notes',
    ];

    protected $casts = [
        'order_datetime' => 'datetime',
        'total_price'    => 'decimal:2',
    ];

    /**
     * Itens deste pedido.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Usuário que criou o registro (quem entrou com o pedido).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuário que prestou o serviço (atendente).
     */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    /**
     * Cliente vinculado (se houver login).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * Estabelecimento (morph).
     */
    public function establishment(): MorphTo
    {
        return $this->morphTo();
    }
}
