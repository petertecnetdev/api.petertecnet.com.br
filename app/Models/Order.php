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
        'attended_at'    => 'datetime',
        'total_price'    => 'decimal:2',
        'total_duration' => 'integer',
    ];

    /** Itens deste pedido */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Usuário que criou o registro */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Colaborador que atendeu o pedido */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(Employer::class, 'attendant_id');
    }

    /** Cliente vinculado (se estiver logado) */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** Usuário que confirmou o agendamento */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Usuário que cancelou o agendamento */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Entidade (Estabelecimento, Evento etc.) */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /** Alias de compatibilidade para $order->establishment */
    public function getEstablishmentAttribute()
    {
        return $this->entity;
    }
}
