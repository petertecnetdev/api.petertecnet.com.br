<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'type',
        'price',
        'limit_date',
        'ticket_type',
        'quantity',
        'description',
    ];

    protected $table = 'tickets';
    protected $primaryKey = 'id';

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'limit_date' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    public function passes()
    {
        return $this->hasMany(EventPass::class);
    }
}
