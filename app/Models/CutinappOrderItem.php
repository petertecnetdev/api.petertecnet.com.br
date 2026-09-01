<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutinappOrderItem extends Model
{
    protected $table = 'cutinapp_order_items';
    protected $fillable = ['order_id','type','ticket_id','event_item_id','name','unit_price','quantity','subtotal','metadata'];
    protected $casts = ['unit_price'=>'decimal:2','subtotal'=>'decimal:2','quantity'=>'integer','metadata'=>'array'];

    public function order(){ return $this->belongsTo(CutinappOrder::class, 'order_id'); }
    public function ticket(){ return $this->belongsTo(Ticket::class); }
    public function eventItem(){ return $this->belongsTo(CutinappEventItem::class, 'event_item_id'); }
}
