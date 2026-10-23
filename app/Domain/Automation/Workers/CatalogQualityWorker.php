<?php

namespace App\Domain\Automation\Workers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogQualityWorker
{
    /**
     * Read-only quality metrics; no publication, ownership transfer or ticket sale.
     *
     * @return array<string,int>
     */
    public function run(int $appId): array
    {
        $events = DB::table('events')->where('app_id', $appId)
            ->where('start_date', '>=', now())
            ->where('start_date', '<=', now()->addDays(90));
        $productions = DB::table('establishments as e')->where('e.app_id', $appId)
            ->where('e.category', 'production');

        // Production logos may live on the legacy establishment row or in the
        // generic app-scoped file library. Consider both, never foreign apps.
        $withoutLogo = (clone $productions)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('files as f')
                    ->whereColumn('f.entity_id', 'e.id')
                    ->whereColumn('f.app_id', 'e.app_id')
                    ->where('f.entity_name', 'establishment')
                    ->where('f.type', 'logo')
                    ->where('f.status', 'active');
            });

        if (Schema::hasColumn('establishments', 'logo')) {
            $withoutLogo->where(fn ($q) => $q->whereNull('e.logo')->orWhere('e.logo', ''));
        }

        $artists = DB::table('artists')->where('app_id', $appId)->whereNull('deleted_at');

        return [
            'upcoming_events' => (clone $events)->count(),
            'events_without_image' => (clone $events)
                ->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))->count(),
            'events_without_description' => (clone $events)
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))->count(),
            'productions_without_logo' => $withoutLogo->count(),
            'artists_without_photo' => (clone $artists)
                ->where(fn ($q) => $q->whereNull('photo')->orWhere('photo', ''))->count(),
            'artists_without_bio' => (clone $artists)
                ->where(fn ($q) => $q->whereNull('bio')->orWhere('bio', ''))->count(),
        ];
    }
}
