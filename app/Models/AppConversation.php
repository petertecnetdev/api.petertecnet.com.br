<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AppConversation extends Model
{
    protected $fillable = [
        'application_id',
        'type',
        'direct_key',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'app_conversation_participants', 'conversation_id', 'user_id')
            ->withPivot(['unread_count', 'last_read_at', 'joined_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AppMessage::class, 'conversation_id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(AppMessage::class, 'conversation_id')->latestOfMany();
    }
}
