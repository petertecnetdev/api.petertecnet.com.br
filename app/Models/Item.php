<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'name',
        'type',
        'description',
        'price',
        'stock',
        'status',
        'limited_by_user',
        'category',
        'availability_start',
        'availability_end',
        'image',
        'is_featured',
    ];

    // Relacionamento com o modelo Event
    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    // Relacionamento com o modelo User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Verifica se o item está disponível no momento
    public function isAvailable()
    {
        $now = now();
        return $this->status && 
               (!$this->availability_start || $this->availability_start <= $now) && 
               (!$this->availability_end || $this->availability_end >= $now);
    }
}
