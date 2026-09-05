<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'reply_to_message_id',
        'type',
        'client_token',
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

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AppMessageAttachment::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(AppMessageReaction::class, 'message_id');
    }
}
