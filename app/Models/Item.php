<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Usuário que criou o item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Aplicativo associado.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Usuário criador.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuário que atualizou.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Relacionamento com Establishment.
     */
    public function establishment(): BelongsTo
    {
        return $this
            ->belongsTo(Establishment::class, foreignKey: 'entity_id')
            ->where('entity_name', 'establishment');
    }

    /**
     * Relacionamento com Barbershop.
     */
    public function barbershop(): BelongsTo
    {
        return $this
            ->belongsTo(Barbershop::class, 'entity_id')
            ->where('entity_name', 'barbershop');
    }

    /**
     * Verifica disponibilidade.
     */
    public function isAvailable(): bool
    {
        return $this->status
            && ($this->stock > 0)
            && (is_null($this->availability_start) || $this->availability_start <= now())
            && (is_null($this->availability_end) || $this->availability_end >= now());
    }

    public function orderItems()
{
    return $this->hasMany(OrderItem::class, 'item_id');
}
}
