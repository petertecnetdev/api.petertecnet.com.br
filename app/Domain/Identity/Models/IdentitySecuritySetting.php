<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentitySecuritySetting extends Model
{
    protected $fillable = [
        'user_id', 'two_factor_enabled', 'two_factor_secret',
        'two_factor_recovery_codes', 'recovery_codes_generated_at',
        'last_recovery_code_used_at', 'two_factor_confirmed_at', 'last_step_up_at',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'recovery_codes_generated_at' => 'datetime',
        'last_recovery_code_used_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'last_step_up_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
