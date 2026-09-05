<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialAuthChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'token_hash',
        'provider',
        'provider_user_id',
        'username',
        'display_name',
        'avatar_url',
        'metadata',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isAvailable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && now()->lessThanOrEqualTo($this->expires_at);
    }
}
