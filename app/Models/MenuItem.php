<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu_itens';

    protected $fillable = [
        'menu_id',
        'item_id',
        'display_order',
        'is_active',
        'price_override',
    ];

    protected $casts = [
        'display_order'  => 'integer',
        'is_active'      => 'boolean',
        'price_override' => 'float',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
