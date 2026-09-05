<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'username',
        'display_name',
        'avatar_url',
        'metadata',
        'connected_at',
        'last_authenticated_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'connected_at' => 'datetime',
        'last_authenticated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
