<?php

namespace App\Domain\Platform\Services;

use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

final class ApplicationAdminEstablishmentService
{
    public function __construct(private readonly ApplicationAdminService $admin)
    {
    }

    public function paginate(int $applicationId, string $category, array $filters): LengthAwarePaginator
    {
        $query = Establishment::query()
            ->where('app_id', $applicationId)
            ->where('category', $category)
            ->with('user:id,first_name,last_name,email')
            ->withCount('employers')
            ->addSelect([
                'events_count' => Event::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('events.production_id', 'establishments.id')
                    ->where('events.app_id', $applicationId),
            ]);

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

    public function update(
        int $applicationId,
        string $category,
        int $establishmentId,
        array $changes,
        ?User $actor,
        array $auditContext = [],
    ): Establishment {
        $model = Establishment::query()
            ->where('app_id', $applicationId)
            ->where('category', $category)
            ->findOrFail($establishmentId);

        $before = Arr::only($model->toArray(), array_keys($changes));
        $model->fill($changes)->save();
        $fresh = $model->fresh()->load('user:id,first_name,last_name,email')->loadCount('employers');
        $fresh->setAttribute('events_count', Event::query()
            ->where('app_id', $applicationId)
            ->where('production_id', $fresh->id)
            ->count());

        $this->admin->auditAction($applicationId, $actor, $model->user, 'admin_establishment_updated', [
            'establishment_id' => $model->id,
            'category' => $category,
            'before' => $before,
            'after' => Arr::only($fresh->toArray(), array_keys($changes)),
        ], $auditContext);

        return $fresh;
    }
}
