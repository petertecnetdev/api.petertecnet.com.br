<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EventProducerProfile extends Model
{
    protected $fillable = [
        'application_id',
        'establishment_id',
        'capacity',
        'start_date',
        'end_date',
        'ticket_price_min',
        'ticket_price_max',
        'total_tickets_sold',
        'total_tickets_available',
        'contact_email',
        'contact_phone',
        'other_information',
        'images',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'establishment_id' => 'integer',
        'capacity' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'ticket_price_min' => 'decimal:2',
        'ticket_price_max' => 'decimal:2',
        'total_tickets_sold' => 'integer',
        'total_tickets_available' => 'integer',
        'images' => 'array',
    ];

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }
}
