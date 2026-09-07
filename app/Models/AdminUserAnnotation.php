<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminUserAnnotation extends Model
{
    protected $fillable = [
        'target_user_id',
        'actor_user_id',
        'kind',
        'value',
        'is_pinned',
        'metadata',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'metadata' => 'array',
    ];

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
