<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = [
        'app_id',
        'user_id',
        'type',
        'title',
        'message',
        'reference_type',
        'reference_id',
        'reference_url',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AppNotification $notification) {
            if ($notification->type !== 'artist_lineup' || $notification->reference_type !== 'event' || ! $notification->reference_id) {
                return;
            }

            $isPublicCutinappEvent = Event::query()
                ->whereKey($notification->reference_id)
                ->where('app_id', $notification->app_id)
                ->where('app_slug', 'cutinapp')
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->exists();

            if (! $isPublicCutinappEvent) {
                return false;
            }
        });
    }
}
