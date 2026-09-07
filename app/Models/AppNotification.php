<?php

namespace App\Models;

use App\Mail\AppNotificationMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppNotification extends Model
{
    protected $fillable = [
        'app_id',
        'user_id',
        'campaign_id',
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

            $isPublicEvent = Event::query()
                ->whereKey($notification->reference_id)
                ->where('app_id', $notification->app_id)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where('is_private', false)
                ->exists();

            if (! $isPublicEvent) {
                return false;
            }
        });

        static::created(function (AppNotification $notification): void {
            try {
                $application = Application::query()->find($notification->app_id);
                if (! $application || strtolower((string) $application->slug) !== 'kryvion') {
                    return;
                }

                $user = User::query()->find($notification->user_id);
                if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    return;
                }

                $emailEnabled = NotificationPreference::query()
                    ->where('app_id', $notification->app_id)
                    ->where('user_id', $notification->user_id)
                    ->value('email_enabled');

                if ($emailEnabled === false || $emailEnabled === 0) {
                    return;
                }

                Mail::to($user->email)->queue(new AppNotificationMail(
                    notification: $notification,
                    recipientUser: $user,
                    application: $application,
                ));
            } catch (\Throwable $exception) {
                Log::warning('Kryvion notification email could not be queued.', [
                    'notification_id' => $notification->id,
                    'app_id' => $notification->app_id,
                    'user_id' => $notification->user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function campaign()
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }
}
