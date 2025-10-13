<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'establishment_id',
        'role',
        'permissions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    // Relacionamento com o usuário vinculado ao colaborador
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relacionamento com o estabelecimento do colaborador
    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    // Relacionamento com o usuário que criou o registro
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Relacionamento com o usuário que atualizou o registro
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
