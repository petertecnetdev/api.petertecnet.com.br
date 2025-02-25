<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Action extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'entity_type',
        'reaction_type',
        'comment',
        'user_id',
    ];

    // Defina as relações com outras models, se necessário
    // public function entity()
    // {
    //     return $this->belongsTo(Entity::class, 'entity_id');
    // }

    // public function user()
    // {
    //     return $this->belongsTo(User::class, 'user_id');
    // }
}
