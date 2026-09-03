<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = ['user_id', 'namespace', 'key', 'value'];

    protected $casts = ['value' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
