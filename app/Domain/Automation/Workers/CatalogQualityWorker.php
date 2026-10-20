<?php

namespace App\Domain\Automation\Workers;

use Illuminate\Support\Facades\DB;

final class CatalogQualityWorker
{
    /**
     * A read-only diagnostic: no implicit publishing, image copying or ticket sales.
     * The same worker works on any app with these common catalog entities.
     *
     * @return array<string,int>
     */
    public function run(int $appId): array
    {
        $events = DB::table('events')->where('app_id', $appId)
            ->where('start_date', '>=', now())
            ->where('start_date', '<=', now()->addDays(90));

        $productions = DB::table('establishments')->where('app_id', $appId);
        $artists = DB::table('artists')->where('app_id', $appId)->whereNull('deleted_at');

        return [
            'upcoming_events' => (clone $events)->count(),
            'events_without_image' => (clone $events)
                ->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))->count(),
            'events_without_description' => (clone $events)
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))->count(),
            'productions_without_logo' => (clone $productions)
                ->where(fn ($q) => $q->whereNull('logo')->orWhere('logo', ''))->count(),
            'artists_without_photo' => (clone $artists)
                ->where(fn ($q) => $q->whereNull('photo')->orWhere('photo', ''))->count(),
            'artists_without_bio' => (clone $artists)
                ->where(fn ($q) => $q->whereNull('bio')->orWhere('bio', ''))->count(),
        ];
    }
}
