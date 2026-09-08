<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceCouponRedemption extends Model
{
    protected $fillable = ['app_id','coupon_id','order_id','user_id','discount_amount','redeemed_at'];
    protected $casts = ['app_id'=>'integer','coupon_id'=>'integer','order_id'=>'integer','user_id'=>'integer','discount_amount'=>'decimal:2','redeemed_at'=>'datetime'];

    public function coupon(){ return $this->belongsTo(CommerceCoupon::class, 'coupon_id'); }
    public function order(){ return $this->belongsTo(CommerceOrder::class, 'order_id'); }
}
