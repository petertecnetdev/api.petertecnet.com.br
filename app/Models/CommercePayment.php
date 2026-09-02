<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercePayment extends Model
{
    protected $table = 'commerce_payments';
    protected $fillable = ['order_id','provider','method','status','provider_payment_id','provider_txid','idempotency_key','amount','provider_fee','qr_code','qr_code_image','ticket_url','provider_payload','paid_at','refunded_at','failed_at'];
    protected $casts = ['amount'=>'decimal:2','provider_fee'=>'decimal:2','provider_payload'=>'array','paid_at'=>'datetime','refunded_at'=>'datetime','failed_at'=>'datetime'];
    public function order(){return $this->belongsTo(CommerceOrder::class,'order_id');}
}
