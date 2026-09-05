<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EventAnnouncement extends Model
{
    protected $fillable = [
        'app_id',
        'event_id',
        'created_by',
        'title',
        'message',
        'level',
        'audience',
        'is_pinned',
        'send_notification',
        'starts_at',
        'ends_at',
        'published_at',
        'notification_sent_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'send_notification' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'notification_sent_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleNow(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where(function (Builder $q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}
