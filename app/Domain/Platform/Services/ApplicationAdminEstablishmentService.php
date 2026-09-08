<?php

namespace App\Domain\Platform\Services;

use App\Models\Establishment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ApplicationAdminEstablishmentService
{
    public function paginate(int $applicationId, string $category, array $filters): LengthAwarePaginator
    {
        $query = Establishment::query()
            ->where('app_id', $applicationId)
            ->where('category', $category)
            ->with('user:id,first_name,last_name,email');

        if ($category === 'production') {
            $query->withCount([
                'employers',
                'events as events_count' => fn ($eventQuery) => $eventQuery,
            ]);
        }

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('fantasy', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$term.'%')));
        }

        match ($filters['status'] ?? null) {
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            default => null,
        };

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function update(int $applicationId, string $category, int $establishmentId, array $changes): Establishment
    {
        $model = Establishment::query()
            ->where('app_id', $applicationId)
            ->where('category', $category)
            ->findOrFail($establishmentId);

        $model->fill($changes)->save();

        return $model->fresh()
            ->load('user:id,first_name,last_name,email')
            ->loadCount('employers');
    }
}
