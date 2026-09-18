<?php

namespace App\Domain\People\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArtistIdentityService
{
    public function __construct(private readonly ArtistReferenceService $references)
    {
    }

    public function resolveUser(string $identifier): ?User
    {
        $value = trim($identifier);
        if ($value === '') return null;

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->first();
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) >= 8) {
            return User::query()->where(function ($query) use ($digits) {
                $query->where('phone_normalized', $digits)->orWhere('phone', $digits)->orWhere('cpf', $digits);
            })->first();
        }

        $username = ltrim($value, '@');
        return User::query()->whereRaw('LOWER(user_name) = ?', [mb_strtolower($username)])->first();
    }

    public function getOrCreate(
        int $appId,
        User $user,
        User $actor,
        ?Event $sourceEvent = null,
        string $source = 'producer_event'
    ): Artist {
        return DB::transaction(function () use ($appId, $user, $actor, $sourceEvent, $source) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = Artist::query()
                ->where('app_id', $appId)
                ->where('user_id', $user->id)
                ->where('artist_type', 'solo')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($sourceEvent) {
                    $legacyProducerOrigin = ! $existing->origin_type
                        && $existing->created_by_user_id
                        && (int) $existing->created_by_user_id !== (int) $user->id;

                    $this->references->recordEventRelationship(
                        $existing,
                        $sourceEvent,
                        $actor,
                        $legacyProducerOrigin
                    );
                } elseif (! $existing->origin_type) {
                    $this->references->markSelfOrigin($existing);
                }

                return $existing->fresh();
            }

            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
                ?: ($user->user_name ?: 'Artista');

            $artist = Artist::query()->create([
                'app_id' => $appId,
                'user_id' => $user->id,
                'created_by_user_id' => $actor->id,
                'claimed_at' => now(),
                'slug' => $this->uniqueSlug($appId, $name),
                'artist_type' => 'solo',
                'stage_name' => $name,
                'city' => $user->city,
                'uf' => $user->uf,
                'photo' => $user->avatar,
                'verification_status' => 'account_linked',
                'is_active' => true,
                'is_published' => true,
                'profile_completion' => 25,
                'origin_type' => $sourceEvent ? 'organization' : 'self',
                'origin_id' => $sourceEvent?->production_id,
                'origin_label' => $sourceEvent?->production?->name,
                'reference_visible' => (bool) $sourceEvent,
            ]);

            if ($sourceEvent) {
                $this->references->recordEventRelationship($artist, $sourceEvent, $actor, true);
                DB::table('artist_analytics_events')->insert([
                    'app_id' => $appId,
                    'artist_id' => $artist->id,
                    'event_id' => $sourceEvent->id,
                    'user_id' => $user->id,
                    'event_type' => 'artist_identity_created',
                    'source' => $source,
                    'session_hash' => null,
                    'metadata' => json_encode([
                        'origin_type' => 'organization',
                        'origin_id' => $sourceEvent->production_id,
                    ]),
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $this->references->markSelfOrigin($artist);
            }

            return $artist->fresh();
        }, 3);
    }

    private function uniqueSlug(int $appId, string $name): string
    {
        $base = Str::slug($name) ?: 'artista';
        $slug = $base;
        $counter = 2;
        while (Artist::withTrashed()->where('app_id', $appId)->where('slug', $slug)->exists()) $slug = $base.'-'.$counter++;
        return $slug;
    }
}
