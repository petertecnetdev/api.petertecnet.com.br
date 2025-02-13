<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
<<<<<<< HEAD
use Illuminate\Database\Eloquent\Model;
=======
>>>>>>> origin/main
use Illuminate\Database\Eloquent\SoftDeletes;

class Barber extends User
{
    use HasFactory, SoftDeletes;
    // Definir os campos que podem ser atribuídos em massa (mass assignment)
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'slug',
        'phone',
        'barbershops',
        'avatar',
        'bio',
        'specialties',
        'experience_years',
        'working_hours',
        'status',
        'rating',
        'price_range',
        'social_media_links',
        'languages',
        'certifications'
    ];

    // Definir os campos que devem ser tratados como tipo JSON
    protected $casts = [
        'barbershops' => 'array',
        'working_hours' => 'array',
        'social_media_links' => 'array',
        'certifications' => 'array',
    ];

    // Relacionamento com o usuário (gerente da barbearia)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function barbershops()
    {
        return $this->belongsToMany(Barbershop::class, 'barber_barbershop')
            ->withTimestamps(); // Inclui os timestamps criados pela tabela intermediária
    }
}
