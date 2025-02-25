<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemMenu extends Model
{
    protected $fillable = ['image', 'name', 'description', 'slug', 'category', 'stock', 'price', 'entity_id', 'entity_name'];

    public function entity()
    {
        if ($this->entity_name === 'production') {
            return $this->belongsTo(Production::class, 'entity_id');
        } elseif ($this->entity_name === 'event') {
            return $this->belongsTo(Event::class, 'entity_id');
        }
    }
}

