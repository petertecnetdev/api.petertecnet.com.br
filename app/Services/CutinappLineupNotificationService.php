<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class CutinappLineupNotificationService
{
    public function notifyPublishedEvent(Event $event): void
    {
        if ($event->app_slug !== 'cutinapp' || ! $event->is_published || $event->is_cancelled) {
            return;
        }

        $event->loadMissing('artists');
        $artists = $event->artists
            ->where('app_id', (int) $event->app_id)
            ->where('is_published', true);

        foreach ($artists as $artist) {
            $followers = DB::table('cutinapp_follows')
                ->where('app_id', $event->app_id)
                ->where('target_type', 'artist')
                ->where('target_id', $artist->id)
                ->pluck('user_id');

            foreach ($followers as $userId) {
                $alreadyNotified = AppNotification::query()
                    ->where('app_id', $event->app_id)
                    ->where('user_id', $userId)
                    ->where('type', 'artist_lineup')
                    ->where('reference_type', 'event')
                    ->where('reference_id', $event->id)
                    ->where('data->artist_id', $artist->id)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                AppNotification::create([
                    'app_id' => $event->app_id,
                    'user_id' => $userId,
                    'type' => 'artist_lineup',
                    'title' => $artist->stage_name . ' confirmado em evento',
                    'message' => $artist->stage_name . ' fará parte de ' . $event->title . '.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/' . $event->slug,
                    'data' => ['artist_id' => $artist->id, 'event_id' => $event->id],
                ]);
            }
        }
    }
}
