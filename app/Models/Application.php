<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'slug',
        'url',
        'logo',
        'is_active',
        'version',
        'author',
        'release_date',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'release_date'=> 'datetime',
    ];

    /**
     * Relacionamento com agendamentos
     */
    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'app_id');
    }
}
