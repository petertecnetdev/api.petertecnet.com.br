<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('artists')
            || ! Schema::hasTable('artist_relationships')
            || ! Schema::hasColumn('artists', 'origin_type')) {
            return;
        }

        DB::table('artists')
            ->whereNull('origin_type')
            ->orderBy('id')
            ->chunkById(200, function ($artists): void {
                foreach ($artists as $artist) {
                    $creatorIsOwner = $artist->user_id
                        && (! $artist->created_by_user_id || (int) $artist->created_by_user_id === (int) $artist->user_id);

                    if ($creatorIsOwner) {
                        DB::table('artists')->where('id', $artist->id)->update([
                            'origin_type' => 'self',
                            'origin_id' => null,
                            'origin_label' => null,
                            'reference_visible' => false,
                            'updated_at' => now(),
                        ]);
                        continue;
                    }

                    $firstEvent = DB::table('event_artist')
                        ->join('events', 'events.id', '=', 'event_artist.event_id')
                        ->leftJoin('establishments', 'establishments.id', '=', 'events.production_id')
                        ->where('event_artist.artist_id', $artist->id)
                        ->whereNotNull('events.production_id')
                        ->orderByRaw('COALESCE(event_artist.invited_at, event_artist.created_at, events.created_at) asc')
                        ->select([
                            'events.id as event_id',
                            'events.production_id',
                            'establishments.name as production_name',
                            'event_artist.invited_by_user_id',
                        ])
                        ->first();

                    if (! $firstEvent) {
                        DB::table('artists')->where('id', $artist->id)->update([
                            'origin_type' => $artist->user_id ? 'self' : null,
                            'reference_visible' => false,
                            'updated_at' => now(),
                        ]);
                        continue;
                    }

                    DB::table('artists')->where('id', $artist->id)->update([
                        'origin_type' => 'organization',
                        'origin_id' => (int) $firstEvent->production_id,
                        'origin_label' => $firstEvent->production_name,
                        'reference_visible' => true,
                        'updated_at' => now(),
                    ]);

                    DB::table('artist_relationships')->updateOrInsert(
                        [
                            'app_id' => (int) $artist->app_id,
                            'artist_id' => (int) $artist->id,
                            'related_type' => 'organization',
                            'related_id' => (int) $firstEvent->production_id,
                            'relationship_type' => 'introduced_by',
                        ],
                        [
                            'first_event_id' => (int) $firstEvent->event_id,
                            'is_public' => true,
                            'status' => 'active',
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                            'metadata' => json_encode([
                                'organization_name' => $firstEvent->production_name,
                                'actor_user_id' => $firstEvent->invited_by_user_id,
                                'backfilled' => true,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        // Historical origin/reference data is intentionally retained.
    }
};
