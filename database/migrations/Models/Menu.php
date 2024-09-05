<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_id',
        'items', // Adicione o campo 'items' aqui
        'name',
    ];

    protected $casts = [
        'items' => 'json',
    ];

    public function production()
    {
        return $this->belongsTo(Production::class);
    }
}
