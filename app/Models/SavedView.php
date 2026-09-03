<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    protected $fillable = ['user_id', 'scope', 'name', 'configuration', 'is_default'];

    protected $casts = [
        'configuration' => 'array',
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
