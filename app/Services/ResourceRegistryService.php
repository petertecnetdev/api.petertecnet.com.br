<?php

namespace App\Services;

use App\Models\ResourceRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceRegistryService
{
    public function register(
        int $applicationId,
        string $resourceType,
        int $resourceId,
        ?int $establishmentId = null,
        ?string $label = null,
        array $metadata = [],
    ): ResourceRef {
        $resourceType = strtolower(trim($resourceType));
        if ($resourceType === '' || $resourceId < 1) {
            throw ValidationException::withMessages(['resource' => ['Tipo e ID de recurso são obrigatórios.']]);
        }

        if ($establishmentId && ! $this->establishmentBelongsToApplication($establishmentId, $applicationId)) {
            throw ValidationException::withMessages(['establishment_id' => ['O estabelecimento não pertence à aplicação informada.']]);
        }

        return ResourceRef::query()->updateOrCreate(
            [
                'application_id' => $applicationId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ],
            [
                'establishment_id' => $establishmentId,
                'label' => $label,
                'status' => 'active',
                'metadata' => $metadata ?: null,
            ],
        );
    }

    public function findActive(string $uuid): ResourceRef
    {
        return ResourceRef::query()->active()->where('uuid', $uuid)->firstOrFail();
    }

    public function query(int $applicationId, ?string $resourceType = null, ?string $search = null): Builder
    {
        return ResourceRef::query()
            ->active()
            ->with(['application:id,name,slug', 'establishment:id,name,fantasy,slug'])
            ->where('application_id', $applicationId)
            ->when($resourceType, fn (Builder $q) => $q->where('resource_type', strtolower(trim($resourceType))))
            ->when($search, function (Builder $q) use ($search) {
                $needle = '%' . trim($search) . '%';
                $q->where(function (Builder $searchQuery) use ($needle) {
                    $searchQuery->where('label', 'like', $needle)
                        ->orWhere('uuid', 'like', $needle)
                        ->orWhereRaw('CAST(resource_id AS CHAR) LIKE ?', [$needle]);
                });
            });
    }

    public function establishmentBelongsToApplication(int $establishmentId, int $applicationId): bool
    {
        return DB::table('establishments')
            ->where('id', $establishmentId)
            ->where(function ($query) use ($applicationId) {
                $query->where('app_id', $applicationId)
                    ->orWhereExists(function ($subquery) use ($applicationId) {
                        $subquery->selectRaw('1')
                            ->from('application_establishment')
                            ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                            ->where('application_establishment.application_id', $applicationId);
                    });
            })
            ->exists();
    }
}
