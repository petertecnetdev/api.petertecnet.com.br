<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory;

    protected $table = 'cities'; // Nome da tabela no banco de dados

    protected $fillable = [
        'uf',
        'estado',
        'city'
    ];
}
