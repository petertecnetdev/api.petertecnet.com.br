<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutinappPayment extends Model
{
    protected $table = 'cutinapp_payments';
    protected $fillable = ['order_id','provider','method','status','provider_payment_id','provider_txid','amount','qr_code','qr_code_image','provider_payload','paid_at','failed_at'];
    protected $casts = ['amount'=>'decimal:2','provider_payload'=>'array','paid_at'=>'datetime','failed_at'=>'datetime'];

    public function order(){ return $this->belongsTo(CutinappOrder::class, 'order_id'); }
}
