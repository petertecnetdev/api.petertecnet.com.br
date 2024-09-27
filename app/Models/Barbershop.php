<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barbershop extends Model
{
    use HasFactory;

    // Definir a tabela associada
    protected $table = 'barbershops';

    // Definir os campos que podem ser preenchidos
    protected $fillable = [
        'name',
        'email',
        'phone',
        'description',
        'address',
        'city',
        'state',
        'zipcode',
        'website',
        'latitude',
        'longitude',
        'rating',
        'status',
        'user_id',
        'created_by',
        'updated_by',
        'logo',
        'background_image',
        'terms_of_service',
        'social_media_links',
    ];

    // Definir as relações
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Exemplo de um escopo para buscar barbearias ativas
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
