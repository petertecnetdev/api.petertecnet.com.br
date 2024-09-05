<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Item extends Model
{
    use HasFactory;

    protected $table = 'items';

    protected $fillable = [
        'name',
        'quantity',
        'event_id',
        'order_id',
        'type',
        'description',
        'cart_id',
        'is_used',
        'user_id',
    ];

    // Relacionamento com o carrinho
    public function cart()
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    // Relacionamento com o evento
    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relacionamento com a produção
    public function production()
    {
        // Establish the relationship between Item and Production through the event_id attribute
        return $this->hasOneThrough(
            Production::class,
            Event::class,
            'production_id', // Foreign key on Event model
            'id', // Foreign key on Production model
            'event_id' // Local key on Item model
        );
    }
}
