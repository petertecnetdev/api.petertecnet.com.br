<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu';

    protected $fillable = [
        'establishment_id',
        'name',
        'slug',
        'description',
        'valid_from',
        'valid_to',
        'price_modifier_percent',
        'is_active',
        'cover_image',
        'pdf_path',
    ];

    protected $casts = [
        'valid_from'             => 'date',
        'valid_to'               => 'date',
        'price_modifier_percent' => 'float',
        'is_active'              => 'boolean',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'menu_itens')
            ->withPivot(['display_order', 'is_active', 'price_override'])
            ->withTimestamps();
    }
}
