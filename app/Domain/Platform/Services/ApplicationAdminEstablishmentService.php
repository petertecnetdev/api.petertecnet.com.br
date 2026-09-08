<?php

namespace App\Domain\Platform\Services;

use App\Models\Production;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ApplicationAdminEstablishmentService
{
    public function paginateProductions(int $applicationId, array $filters): LengthAwarePaginator
    {
        $query = Production::query()
            ->where('app_id', $applicationId)
            ->with('user:id,first_name,last_name,email')
            ->withCount(['events', 'employers']);

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

    public function updateProduction(int $applicationId, int $productionId, array $changes): Production
    {
        $model = Production::query()
            ->where('app_id', $applicationId)
            ->findOrFail($productionId);

        $model->fill($changes)->save();

        return $model->fresh()
            ->load('user:id,first_name,last_name,email')
            ->loadCount(['events', 'employers']);
    }
}
