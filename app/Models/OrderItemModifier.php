<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItemModifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'modifier_id',
        'quantity',
        'type',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function modifier()
    {
        return $this->belongsTo(Item::class, 'modifier_id');
    }
}
