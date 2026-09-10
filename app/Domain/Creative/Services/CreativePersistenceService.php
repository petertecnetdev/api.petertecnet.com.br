<?php

namespace App\Domain\Creative\Services;

use App\Domain\Creative\Models\CreativeGeneration;
use App\Domain\Creative\Models\CreativeProfile;
use Illuminate\Support\Facades\Schema;

final class CreativePersistenceService
{
    public function profile(int $applicationId, ?string $ownerType, ?int $ownerId): array
    {
        if (! $ownerType || ! $ownerId || ! Schema::hasTable('creative_profiles')) {
            return [];
        }

        $profile = CreativeProfile::query()->where([
            'application_id' => $applicationId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ])->first();

        return $profile ? [
            'brand_colors' => $profile->brand_colors ?? [],
            'preferred_style' => $profile->preferred_style,
            'preferred_intensity' => $profile->preferred_intensity,
            'brand_context' => $profile->brand_context,
            'preferences' => $profile->preferences ?? [],
        ] : [];
    }

    public function rememberProfile(int $applicationId, ?string $ownerType, ?int $ownerId, array $data): void
    {
        if (! $ownerType || ! $ownerId || ! Schema::hasTable('creative_profiles')) {
            return;
        }

        CreativeProfile::query()->updateOrCreate([
            'application_id' => $applicationId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ], [
            'brand_colors' => $data['brand_colors'] ?? [],
            'preferred_style' => $data['style'] ?? null,
            'preferred_intensity' => $data['intensity'] ?? null,
            'brand_context' => $data['brand_context'] ?? null,
            'preferences' => [
                'reference_notes' => $data['reference_notes'] ?? null,
            ],
        ]);
    }

    public function memory(int $applicationId, ?string $ownerType, ?int $ownerId, int $limit = 8): array
    {
        if (! $ownerType || ! $ownerId || ! Schema::hasTable('creative_generations')) {
            return [];
        }

        return CreativeGeneration::query()
            ->where('application_id', $applicationId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->latest('id')
            ->limit(max(1, min(20, $limit)))
            ->get(['style', 'variation', 'format'])
            ->map(fn (CreativeGeneration $item): string => trim(implode(' / ', array_filter([
                $item->style, $item->variation, $item->format,
            ]))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function record(array $data): ?CreativeGeneration
    {
        if (! Schema::hasTable('creative_generations')) {
            return null;
        }

        return CreativeGeneration::query()->create($data);
    }

    public function history(int $applicationId, ?string $ownerType, ?int $ownerId, int $limit = 12): array
    {
        if (! Schema::hasTable('creative_generations')) {
            return [];
        }

        return CreativeGeneration::query()
            ->where('application_id', $applicationId)
            ->when($ownerType, fn ($q) => $q->where('owner_type', $ownerType))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->latest('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (CreativeGeneration $item): array => [
                'id' => $item->id,
                'subject' => $item->subject,
                'format' => $item->format,
                'style' => $item->style,
                'intensity' => $item->intensity,
                'variation' => $item->variation,
                'generation_mode' => $item->generation_mode,
                'model' => $item->model,
                'quality_score' => $item->quality_score,
                'selected' => $item->selected,
                'metadata' => $item->metadata,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->all();
    }
}
