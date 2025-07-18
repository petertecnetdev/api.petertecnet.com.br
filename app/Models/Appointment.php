<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

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
        // força o cast para datetime, assim scheduled_at é Carbon
        'scheduled_at'      => 'datetime:Y-m-d H:i:s',
        'expected_end_time' => 'datetime:Y-m-d H:i:s',
        'service_ids'       => 'array',
    ];

    /** Quem presta o serviço (User genérico) */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /** Perfil de Barber (caso exista) */
    public function barber(): HasOne
    {
        return $this->hasOne(Barber::class, 'user_id', 'provider_id');
    }

    /** Entidade polimórfica (Barbershop, Hospital…) */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /** Retorna array de nomes dos serviços */
    public function getServiceNamesAttribute(): array
    {
        if (! is_array($this->service_ids)) {
            return [];
        }
        return Item::whereIn('id', $this->service_ids)
            ->where('category', 'Serviços')
            ->pluck('name')
            ->toArray();
    }
}
