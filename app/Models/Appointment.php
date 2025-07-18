<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Appointment extends Model
{
    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'scheduled_at',
        'expected_end_time',
        'service_ids',
        'provider_id',
        'client_id',
        'registered_by',
        'status',
        'location',
        'duration',
        'notes',
        'payment_status',
        'appointment_type',
        'attendance_status',
        'client_confirmation',
    ];

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * Quem presta o serviço: sempre um User
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }

    /**
     * Perfil de Barber do provider (se existir)
     */
    public function providerBarber(): BelongsTo
    {
        return $this->hasOne(\App\Models\Barber::class, 'user_id', 'provider_id');
    }

    /**
     * Entidade polimórfica (Barbershop, Hospital, Dentist…)
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Accessor: pluck dos nomes dos serviços
     */
    public function getServiceNamesAttribute()
    {
        if (! is_array($this->service_ids)) {
            return collect();
        }
        return \App\Models\Item::whereIn('id', $this->service_ids)
            ->where('category', 'Serviços')
            ->pluck('name');
    }
}
