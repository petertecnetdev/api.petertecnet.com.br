<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_id',
        'entity_type',
        'interaction_type',
        'comment',
        'name',
        'content',
    ];

    protected $casts = [
        'content' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'entity_id');
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class, 'entity_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'entity_id');
    }
     public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'item');
    }
}
