<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSchedule extends Model
{
    protected $fillable = [
        'app_id',
        'production_id',
        'title',
        'description',
        'category',
        'image',
        'day_of_week',
        'start_time',
        'end_time',
        'venue',
        'address',
        'google_maps_url',
        'city',
        'uf',
        'cep',
        'latitude',
        'longitude',
        'max_attendees',
        'contact_email',
        'contact_phone',
        'is_private',
        'event_format',
        'online_url',
        'is_active',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'production_id' => 'integer',
        'day_of_week' => 'integer',
        'max_attendees' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_private' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    public function generatedEvents()
    {
        return $this->hasMany(Event::class, 'event_schedule_id');
    }
}
