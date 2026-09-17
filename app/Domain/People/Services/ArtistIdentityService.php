<?php

namespace App\Domain\People\Services;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArtistIdentityService
{
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

    public function getOrCreate(int $appId, User $user, User $actor): Artist
    {
        return DB::transaction(function () use ($appId, $user, $actor) {
            $existing = Artist::query()->where('app_id', $appId)->where('user_id', $user->id)->where('artist_type', 'solo')->lockForUpdate()->first();
            if ($existing) return $existing;

            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->user_name ?: 'Artista');
            return Artist::query()->create([
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
            ]);
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
