<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityCredential extends Model
{
    protected $fillable = [
        'user_id', 'type', 'credential_id', 'public_key', 'algorithm',
        'sign_count', 'transports', 'name', 'last_used_at',
    ];

    protected $casts = [
        'algorithm' => 'integer',
        'sign_count' => 'integer',
        'transports' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
