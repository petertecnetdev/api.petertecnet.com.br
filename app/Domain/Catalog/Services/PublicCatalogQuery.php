<?php

namespace App\Domain\Catalog\Services;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PublicCatalogQuery
{
    public function establishments(Application $application): Builder
    {
        $query = Establishment::query()
            ->forApplication((int) $application->id)
            ->where('is_cancelled', false)
            ->where('is_published', true);

        if ($this->approvalRequired($application)) {
            $query->where('is_approved', true);
        }

        return $query;
    }

    public function items(Application $application): Builder
    {
        return Item::query()
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $query) use ($application) {
                $query->forApplication((int) $application->id)
                    ->where('is_cancelled', false)
                    ->where('is_published', true);

                if ($this->approvalRequired($application)) {
                    $query->where('is_approved', true);
                }
            });
    }

    public function approvalRequired(Application $application): bool
    {
        return in_array(
            (string) $application->slug,
            config('platform.approval_required_apps', []),
            true
        );
    }

    public function isPublicInApplication(Establishment $establishment, Application $application): bool
    {
        if (! $application->is_active || $establishment->is_cancelled || ! $establishment->is_published) {
            return false;
        }

        if ($this->approvalRequired($application) && ! $establishment->is_approved) {
            return false;
        }

        if ((int) $establishment->app_id === (int) $application->id) {
            return true;
        }

        if ($establishment->relationLoaded('applications')) {
            return $establishment->applications->contains(
                fn (Application $candidate) => (int) $candidate->id === (int) $application->id
            );
        }

        return $establishment->applications()->whereKey($application->id)->exists();
    }

    /**
     * @return list<int>
     */
    public function publicApplicationIds(Establishment $establishment, Collection $activeApplications): array
    {
        $candidateIds = collect([(int) $establishment->app_id])
            ->merge(
                $establishment->relationLoaded('applications')
                    ? $establishment->applications->pluck('id')
                    : $establishment->applications()->pluck('applications.id')
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $candidateIds
            ->filter(function (int $applicationId) use ($establishment, $activeApplications) {
                $application = $activeApplications->get($applicationId);

                return $application instanceof Application
                    && $this->isPublicInApplication($establishment, $application);
            })
            ->values()
            ->all();
    }
}
