<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    // Definição dos campos que podem ser preenchidos em massa
    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'scheduled_at',
        'service_ids',
        'expected_end_time',
        'provider_id', // Quem irá prestar o serviço
        'description', // Descrição do agendamento
        'client_id', // Cliente que solicitou o agendamento
        'registered_by', // Usuário responsável pelo cadastro
        'status', // Status do agendamento (pendente, confirmado, cancelado, concluído)
        'location', // Local do atendimento (endereço, sala, consultório, etc.)
        'duration', // Duração estimada do serviço
        'notes', // Observações adicionais
        'payment_status', // Status do pagamento (pendente, pago, cancelado)
        'appointment_type', // Tipo de agendamento (presencial, online, domiciliar, etc.)
        'attendance_status'
    ];

    // Cast dos campos para tipos específicos
    protected $casts = [
        'scheduled_at' => 'datetime', // Transforma o campo em um objeto DateTime
        'registered_at' => 'datetime', // Transforma o campo em um objeto DateTime
        'service_ids' => 'json', // Converte o campo 'service_ids' para um array (JSON)
    ];

    // Relacionamento com os serviços
    public function services()
    {
        // Verifica se service_ids é um array antes de buscar os itens relacionados
        if (is_array($this->service_ids)) {
            return Item::whereIn('id', $this->service_ids)
                       ->where('category', 'serviço') // Filtra apenas os itens de serviço
                       ->get(); // Retorna os itens encontrados
        }

        // Caso o campo service_ids não seja um array, retorna uma coleção vazia
        return collect([]);
    }

    // Relacionamento com o provedor (quem prestará o serviço)
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id'); // Relacionamento com o usuário que será o provedor
    }

    // Relacionamento com o cliente (quem solicitou o agendamento)
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id'); // Relacionamento com o usuário que é o cliente
    }

    // Relacionamento com o usuário que registrou o agendamento
    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by'); // Relacionamento com o usuário que registrou
    }
}
