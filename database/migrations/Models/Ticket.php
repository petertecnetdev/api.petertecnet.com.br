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
        // Adicione outros campos relevantes aqui, se houver.
    ];

    // Defina o nome da tabela, se for diferente do padrão
    protected $table = 'tickets';

    // Define a chave primária, se for diferente do padrão (id)
    protected $primaryKey = 'id';

    // Relação com o model Event (um ingresso pertence a um evento)
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    // Relação com o model Production (um ingresso pertence a uma produção)
    public function production()
    {
        return $this->belongsTo(Production::class);
    }
}
