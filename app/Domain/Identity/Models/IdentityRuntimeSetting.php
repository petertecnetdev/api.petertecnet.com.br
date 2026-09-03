<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityRuntimeSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'array'];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
