<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;

class CutinappPublicSocialController extends Controller
{
    public function eventArtists(string $slug)
    {
        $appId = (int) Application::query()->where('slug', 'cutinapp')->where('is_active', true)->value('id');
        abort_unless($appId, 503, 'A Cutinapp não está registrada corretamente na API.');

        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', 'cutinapp')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->firstOrFail();

        return response()->json([
            'artists' => $event->artists()
                ->where('cutinapp_artists.app_id', $appId)
                ->where('cutinapp_artists.is_published', true)
                ->orderByDesc('cutinapp_event_artist.is_headliner')
                ->orderBy('cutinapp_event_artist.sort_order')
                ->get(),
        ]);
    }
}
