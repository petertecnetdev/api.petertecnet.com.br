<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    /**
     * Campos preenchíveis em massa
     * @var array
     */
    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'scheduled_at',
        'expected_end_time',
        'service_ids',
        'provider_id',
        'description',
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

    /**
     * Casts de atributos
     * @var array
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids' => 'array',
    ];

    /**
     * Retorna a entidade polimórfica agendada
     * Pode ser Barbershop, Hospital, Dentista etc.
     * @return MorphTo
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    /**
     * Usuário provedor do serviço (barbeiro, médico, dentista...)
     * @return BelongsTo
     */
    public function provider(): BelongsTo
    {
        // trazendo também o username para construir link no front
        return $this->belongsTo(User::class, 'provider_id')
            ->select(['id', 'first_name', 'username']);
    }

    /**
     * Cliente que solicitou o agendamento
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * Usuário que registrou o agendamento
     * @return BelongsTo
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Retorna nomes dos serviços agendados
     * @return \Illuminate\Support\Collection
     */
    public function getServiceNamesAttribute()
    {
        if (!is_array($this->service_ids)) {
            return collect();
        }
        return Item::whereIn('id', $this->service_ids)
            ->where('category', 'Serviços')
            ->pluck('name');
    }

    /**
     * Inclui slug da entidade no array JSON de retorno
     * @return array
     */
    public function toArray()
    {
        $data = parent::toArray();

        // adiciona slug da entidade (ex: barbershop, hospital...)
        if ($this->entity) {
            $data['entity'] = [
                'type' => $this->entity_name,
                'id' => $this->entity->id,
                'name' => $this->entity->name,
                'slug' => $this->entity->slug ?? null,
            ];
        }

        // adiciona dados do provedor
        if ($this->provider) {
            $data['provider'] = [
                'id' => $this->provider->id,
                'first_name' => $this->provider->first_name,
                'username' => $this->provider->username,
            ];
        }

        // adiciona service_names
        $data['service_names'] = $this->service_names;

        return $data;
    }

}
