<?php

// app/Models/Employer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employer extends Model
{
    protected $fillable = [
        'user_id',
        'establishment_id',
        'role',
        'permissions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'permissions' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** ✅ Relacionamento direto com pedidos (atendimentos) do colaborador */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'attendant_id');
    }

    public function interactions()
{
    return $this->hasMany(Interaction::class, 'entity_id')
        ->where('entity_type', 'employer');
}

}
