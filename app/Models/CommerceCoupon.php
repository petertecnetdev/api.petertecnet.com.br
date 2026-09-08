<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceCoupon extends Model
{
    protected $fillable = [
        'app_id','production_id','event_id','code','name','discount_type','discount_value',
        'minimum_subtotal','max_uses','max_uses_per_user','uses_count','starts_at','expires_at',
        'is_active','created_by',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'production_id' => 'integer',
        'event_id' => 'integer',
        'discount_value' => 'decimal:2',
        'minimum_subtotal' => 'decimal:2',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'uses_count' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function production(){ return $this->belongsTo(Production::class, 'production_id'); }
    public function event(){ return $this->belongsTo(Event::class); }
    public function redemptions(){ return $this->hasMany(CommerceCouponRedemption::class, 'coupon_id'); }
}
