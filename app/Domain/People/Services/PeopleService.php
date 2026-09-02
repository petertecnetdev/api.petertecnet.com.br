<?php

namespace App\Domain\People\Services;

use App\Models\PeopleProfile;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class PeopleService
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = PeopleProfile::query()->where('status', 'active');

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }
        if (! empty($filters['q'])) {
            $term = '%' . trim((string) $filters['q']) . '%';
            $query->where(fn ($q) => $q->where('display_name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? config('platform.default_page_size', 20)), 1), config('platform.max_page_size', 100));
        return $query->orderBy('display_name')->paginate($perPage);
    }

    public function create(array $attributes, ?int $userId = null): PeopleProfile
    {
        $name = trim((string) $attributes['display_name']);
        return PeopleProfile::create([
            'application_id' => $this->applicationContext->id(),
            'user_id' => $attributes['user_id'] ?? $userId,
            'organization_id' => $attributes['organization_id'] ?? null,
            'kind' => $attributes['kind'] ?? 'person',
            'display_name' => $name,
            'slug' => $attributes['slug'] ?? $this->uniqueSlug($name),
            'status' => $attributes['status'] ?? 'active',
            'metadata' => $attributes['metadata'] ?? [],
        ]);
    }

    public function update(PeopleProfile $profile, array $attributes): PeopleProfile
    {
        abort_unless((int) $profile->application_id === $this->applicationContext->id(), 404);
        if (array_key_exists('display_name', $attributes)) {
            $attributes['display_name'] = trim((string) $attributes['display_name']);
        }
        $profile->fill($attributes)->save();
        return $profile->fresh();
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'person';
        $slug = $base;
        $counter = 2;
        while (PeopleProfile::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }
}
