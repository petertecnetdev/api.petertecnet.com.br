<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Appointment extends Model
{
    use HasFactory;

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * Quem presta o serviço: usuário genérico
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }

    /**
     * Se esse usuário for barbeiro, puxa o perfil Barber
     */
    public function barber(): BelongsTo
    {
        // provider_id em appointments → user_id em barbers
        return $this->belongsTo(\App\Models\Barber::class, 'provider_id', 'user_id');
    }

    /**
     * Relação polimórfica: usa as colunas entity_name + entity_id
     */
    public function entity(): MorphTo
    {
        // 1º arg: nome do método, 2º: coluna de tipo, 3º: coluna de id
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    /**
     * Accessor para nomes legíveis de serviço
     */
    public function getServiceNamesAttribute()
    {
        if (! is_array($this->service_ids) || empty($this->service_ids)) {
            return collect();
        }
        return \App\Models\Item::whereIn('id', $this->service_ids)
            ->where('category', 'Serviços')  // ajuste aqui se sua categoria for outra
            ->pluck('name');
    }
}
