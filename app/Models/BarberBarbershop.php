<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BarberBarbershop extends Pivot
{
    // A tabela intermediária não tem campos de timestamps por padrão
    public $timestamps = false;

    // Definindo os campos que podem ser atribuídos em massa
    protected $fillable = [
        'barber_id',
        'barbershop_id'
    ];

    // Relacionamento com o model Barber
    public function barber()
    {
        return $this->belongsTo(Barber::class);
    }

    // Relacionamento com o model Barbershop
    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'barbershop_id');  // Defina a chave estrangeira correta
    }
}
