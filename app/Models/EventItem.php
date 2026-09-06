<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventItem extends Model
{
    protected $table = 'event_items';
    protected $fillable = ['app_id','event_id','source_item_id','name','description','price','quantity','is_active'];
    protected $casts = ['app_id'=>'integer','event_id'=>'integer','source_item_id'=>'integer','price'=>'decimal:2','quantity'=>'integer','is_active'=>'boolean'];
    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function event(){return $this->belongsTo(Event::class);}
    public function sourceItem(){return $this->belongsTo(Item::class,'source_item_id')->withTrashed();}
}
