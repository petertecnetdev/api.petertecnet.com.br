<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceRecord extends Model
{
    use HasFactory;

    // Definição dos campos que podem ser preenchidos em massa
    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'service_ids',
        'provider_id', // Quem prestou o serviço (barbeiro, médico, advogado)
        'client_id', // Cliente/paciente que recebeu o serviço
        'registered_by', // Usuário responsável pelo cadastro
        'discount', // Desconto aplicado
        'payment_method', // Método de pagamento (Pix, Débito, etc.)
        'total_price', // Valor total do serviço
        'status', // Status do atendimento (pendente, concluído, cancelado)
        'notes', // Observações adicionais
    ];

    // Cast dos campos para tipos específicos
    protected $casts = [
        'service_ids' => 'json', // Converte o campo 'service_ids' para um array JSON
        'discount' => 'decimal:2',
        'total_price' => 'decimal:2',
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

    // Relacionamento com o provedor (quem prestou o serviço)
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id'); // Relacionamento com o usuário que prestou o serviço
    }

    // Relacionamento com o cliente (quem recebeu o serviço)
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id'); // Relacionamento com o cliente/paciente atendido
    }

    // Relacionamento com o usuário que registrou o atendimento
    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by'); // Relacionamento com o usuário responsável pelo registro
    }
}
