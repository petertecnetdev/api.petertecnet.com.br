<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'order_number',
        'order_datetime',
        'attendant_id',
        'client_id',
        'customer_name',
        'customer_phone',
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

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function attendant()
    {
        return $this->belongsTo(User::class, 'attendant_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function establishment()
    {
        return $this->morphTo();
    }
}