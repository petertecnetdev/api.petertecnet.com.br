<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityIdentifier extends Model
{
    protected $fillable = [
        'user_id', 'type', 'fingerprint', 'value_encrypted', 'display_hint',
        'is_primary', 'verified_at', 'revoked_at', 'metadata',
    ];

    protected $hidden = ['value_encrypted'];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
