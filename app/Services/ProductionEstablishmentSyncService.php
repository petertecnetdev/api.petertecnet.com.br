<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\Production;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductionEstablishmentSyncService
{
    public function sync(Production $production): ?Establishment
    {
        if (! Schema::hasTable('establishments') || ! Schema::hasColumn('productions', 'establishment_id')) {
            return null;
        }

        if (! $production->app_id || ! $production->user_id) {
            return null;
        }

        return DB::transaction(function () use ($production) {
            $establishment = $this->resolveEstablishment($production) ?? new Establishment();

            if ($establishment->exists && $establishment->trashed()) {
                $establishment->restore();
            }

            $profile = array_merge(
                $this->businessProfile($establishment->business_profile),
                [
                    'source' => 'cutinapp_production',
                    'production_id' => $production->id,
                    'app_slug' => $production->app_slug ?: 'cutinapp',
                ]
            );

            $establishment->forceFill([
                'app_id' => $production->app_id,
                'name' => $production->name,
                'fantasy' => $production->fantasy ?: $production->name,
                'slug' => $this->establishmentSlug($production, $establishment),
                'cnpj' => $production->cnpj,
                'type' => $production->establishment_type ?: ($production->type ?: 'production'),
                'category' => 'production',
                'phone' => $production->phone ?: $production->contact_phone,
                'email' => $production->contact_email,
                'description' => $production->description,
                'additional_info' => $production->additional_info,
                'city' => $production->city,
                'uf' => $production->uf,
                'location' => $production->location,
                'cep' => $production->cep,
                'address' => $production->address,
                'user_id' => $production->user_id,
                'created_by' => $establishment->created_by ?: $production->user_id,
                'updated_by' => $production->user_id,
                'logo' => $production->logo,
                'background' => $production->background,
                'is_featured' => (bool) $production->is_featured,
                'is_published' => (bool) $production->is_published,
                'is_approved' => (bool) $production->is_approved,
                'is_cancelled' => (bool) $production->is_cancelled,
                'website_url' => $production->website_url,
                'facebook_url' => $production->facebook_url,
                'instagram_url' => $production->instagram_url,
                'twitter_url' => $production->twitter_url,
                'youtube_url' => $production->youtube_url,
                'segments' => $production->segments,
                'business_profile' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $establishment->save();

            $this->attachToApplication($establishment, (int) $production->app_id);

            if ((int) $production->establishment_id !== (int) $establishment->id) {
                $production->forceFill(['establishment_id' => $establishment->id])->saveQuietly();
            }

            return $establishment;
        });
    }

    public function handleDeleted(Production $production): void
    {
        if (! Schema::hasColumn('productions', 'establishment_id') || ! $production->establishment_id) {
            return;
        }

        $establishment = Establishment::withTrashed()->find($production->establishment_id);
        if (! $establishment || $establishment->trashed()) {
            return;
        }

        $otherApplicationId = Schema::hasTable('application_establishment')
            ? DB::table('application_establishment')
                ->where('establishment_id', $establishment->id)
                ->where('application_id', '!=', $production->app_id)
                ->value('application_id')
            : null;

        if ($otherApplicationId) {
            DB::table('application_establishment')
                ->where('application_id', $production->app_id)
                ->where('establishment_id', $establishment->id)
                ->delete();

            if ((int) $establishment->app_id === (int) $production->app_id) {
                $establishment->forceFill(['app_id' => (int) $otherApplicationId])->save();
            }
            return;
        }

        $establishment->forceFill([
            'is_published' => false,
            'is_cancelled' => true,
            'updated_by' => $production->user_id,
        ])->save();
        $establishment->delete();
    }

    private function resolveEstablishment(Production $production): ?Establishment
    {
        if ($production->establishment_id) {
            $linked = Establishment::withTrashed()->find($production->establishment_id);
            if ($linked) {
                return $linked;
            }
        }

        if ($production->slug) {
            $bySlug = Establishment::withTrashed()
                ->where('app_id', $production->app_id)
                ->where('slug', $production->slug)
                ->first();
            if ($bySlug) {
                return $bySlug;
            }
        }

        $cnpj = $this->digits($production->cnpj);
        if ($cnpj !== '') {
            return Establishment::withTrashed()
                ->where('app_id', $production->app_id)
                ->whereNotNull('cnpj')
                ->whereRaw("REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') = ?", [$cnpj])
                ->first();
        }

        return null;
    }

    private function establishmentSlug(Production $production, Establishment $establishment): string
    {
        $base = Str::slug($production->slug ?: $production->name) ?: 'production-' . $production->id;
        $candidate = $base;
        $suffix = 2;

        while (Establishment::withTrashed()
            ->when($establishment->exists, fn ($query) => $query->whereKeyNot($establishment->id))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base . '-cutinapp-' . $production->id . ($suffix > 2 ? '-' . $suffix : '');
            $suffix++;
        }

        return $candidate;
    }

    private function attachToApplication(Establishment $establishment, int $applicationId): void
    {
        if (! Schema::hasTable('application_establishment')) {
            return;
        }

        $pivot = DB::table('application_establishment')
            ->where('application_id', $applicationId)
            ->where('establishment_id', $establishment->id);

        if ($pivot->exists()) {
            $pivot->update(['is_primary' => true, 'updated_at' => now()]);
            return;
        }

        DB::table('application_establishment')->insert([
            'application_id' => $applicationId,
            'establishment_id' => $establishment->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function businessProfile(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
