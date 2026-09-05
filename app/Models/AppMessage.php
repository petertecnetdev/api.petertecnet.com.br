<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'type',
        'body',
        'metadata',
        'edited_at',
        'deleted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AppConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
