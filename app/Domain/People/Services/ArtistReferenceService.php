<?php

namespace App\Domain\People\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ArtistReferenceService
{
    public function recordEventRelationship(
        Artist $artist,
        Event $event,
        User $actor,
        bool $createdFromRelationship = false
    ): void {
        $production = $event->production;
        if (! $production) {
            return;
        }

        DB::table('artist_relationships')->updateOrInsert(
            [
                'app_id' => (int) $artist->app_id,
                'artist_id' => (int) $artist->id,
                'related_type' => 'organization',
                'related_id' => (int) $production->id,
                'relationship_type' => $createdFromRelationship ? 'introduced_by' : 'event_collaboration',
            ],
            [
                'first_event_id' => (int) $event->id,
                'is_public' => true,
                'status' => 'active',
                'first_seen_at' => DB::raw('COALESCE(first_seen_at, CURRENT_TIMESTAMP)'),
                'last_seen_at' => now(),
                'metadata' => json_encode([
                    'organization_name' => $production->name,
                    'actor_user_id' => (int) $actor->id,
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        if ($createdFromRelationship && ! $artist->origin_type) {
            $artist->forceFill([
                'origin_type' => 'organization',
                'origin_id' => (int) $production->id,
                'origin_label' => $production->name,
                'reference_visible' => true,
            ])->save();
        }
    }

    public function markSelfOrigin(Artist $artist): void
    {
        if ($artist->origin_type) {
            return;
        }

        $artist->forceFill([
            'origin_type' => 'self',
            'origin_id' => null,
            'origin_label' => null,
            'reference_visible' => false,
        ])->save();
    }

    public function setVisibility(Artist $artist, bool $visible): Artist
    {
        $artist->forceFill(['reference_visible' => $visible])->save();

        if ($artist->origin_type === 'organization' && $artist->origin_id) {
            DB::table('artist_relationships')
                ->where('app_id', $artist->app_id)
                ->where('artist_id', $artist->id)
                ->where('related_type', 'organization')
                ->where('related_id', $artist->origin_id)
                ->where('relationship_type', 'introduced_by')
                ->update([
                    'is_public' => $visible,
                    'updated_at' => now(),
                ]);
        }

        return $artist->fresh();
    }
}
