<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentitySecuritySetting extends Model
{
    protected $fillable = [
        'user_id', 'two_factor_enabled', 'two_factor_secret',
        'two_factor_recovery_codes', 'two_factor_confirmed_at',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
