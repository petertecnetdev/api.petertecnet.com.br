<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceOrderItem extends Model
{
    protected $table = 'commerce_order_items';
    protected $fillable = ['order_id','type','ticket_id','event_item_id','name','unit_price','quantity','subtotal','metadata'];
    protected $casts = ['unit_price'=>'decimal:2','subtotal'=>'decimal:2','quantity'=>'integer','metadata'=>'array'];
    public function order(){return $this->belongsTo(CommerceOrder::class,'order_id');} public function ticket(){return $this->belongsTo(Ticket::class);} public function eventItem(){return $this->belongsTo(EventItem::class,'event_item_id');}
}
