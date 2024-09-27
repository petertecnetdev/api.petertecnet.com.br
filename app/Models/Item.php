<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory;

    // Atributos que podem ser preenchidos em massa
    protected $fillable = [
        'user_id',
        'slug',
        'app_id',
        'name',
        'type',
        'sku',
        'description',
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

    // Atributos que devem ser convertidos para tipos específicos
    protected $casts = [
        'tags' => 'array', // Converte JSON em array
        'availability_start' => 'datetime',
        'availability_end' => 'datetime',
        'expiration_date' => 'datetime',
    ];

    /**
     * Relacionamento com o usuário que cadastrou o item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com o aplicativo associado.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Relacionamento com o usuário que criou o item.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relacionamento com o usuário que atualizou o item.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Função para verificar se o item está disponível.
     */
    public function isAvailable(): bool
    {
        return $this->status && ($this->stock > 0) && 
               (is_null($this->availability_start) || $this->availability_start <= now()) && 
               (is_null($this->availability_end) || $this->availability_end >= now());
    }
}
