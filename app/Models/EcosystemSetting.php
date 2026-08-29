<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcosystemSetting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_public', 'updated_by'];

    protected $casts = [
        'value' => 'array',
        'is_public' => 'boolean',
    ];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
