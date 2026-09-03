<?php

namespace App\Http\Middleware;

use App\Models\Employer;
use App\Models\Order;
use App\Models\SchedulingResource;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ValidateSchedulingAssignment
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        [$establishmentId, $itemIds] = $this->resolveAppointmentContext($request);
        $providerId = $request->filled('provider_id') ? (int) $request->input('provider_id') : null;
        $resourceIds = collect($request->input('resource_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($providerId) {
            $this->validateProvider($providerId, $establishmentId, $itemIds);
        }

        if ($resourceIds->isNotEmpty()) {
            $this->validateResources($resourceIds->all(), $establishmentId, $itemIds);
        }

        return $next($request);
    }

    private function resolveAppointmentContext(Request $request): array
    {
        $appointmentId = (int) ($request->route('appointment') ?: 0);

        if ($appointmentId > 0) {
            $order = Order::query()
                ->whereKey($appointmentId)
                ->where('app_id', $this->context->id())
                ->where('type', 'appointment')
                ->where('entity_name', 'establishment')
                ->firstOrFail();

            $itemIds = DB::table('order_items')
                ->where('order_id', $order->id)
                ->pluck('item_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return [(int) $order->entity_id, $itemIds];
        }

        $establishmentId = (int) $request->input('establishment_id');
        $itemIds = collect($request->input('items', []))
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [$establishmentId, $itemIds];
    }

    private function validateProvider(int $providerId, int $establishmentId, array $itemIds): void
    {
        $provider = Employer::query()
            ->whereKey($providerId)
            ->where('establishment_id', $establishmentId)
            ->whereHas('establishment', fn ($query) => $query->where('app_id', $this->context->id()))
            ->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'provider_id' => ['O profissional informado não pertence a este estabelecimento e aplicativo.'],
            ]);
        }

        if ($itemIds === []) {
            return;
        }

        $supported = DB::table('employer_item')
            ->where('employer_id', $provider->id)
            ->whereIn('item_id', $itemIds)
            ->distinct()
            ->count('item_id');

        if ($supported !== count($itemIds)) {
            throw ValidationException::withMessages([
                'provider_id' => ['O profissional selecionado não executa todos os serviços deste agendamento.'],
            ]);
        }
    }

    private function validateResources(array $resourceIds, int $establishmentId, array $itemIds): void
    {
        $resources = SchedulingResource::query()
            ->whereIn('id', $resourceIds)
            ->where('app_id', $this->context->id())
            ->where('establishment_id', $establishmentId)
            ->where('is_active', true)
            ->get(['id', 'type', 'employer_id']);

        if ($resources->count() !== count($resourceIds)) {
            throw ValidationException::withMessages([
                'resource_ids' => ['Um ou mais recursos não pertencem ao estabelecimento, aplicativo ou estão inativos.'],
            ]);
        }

        $professionalResource = $resources->first(fn (SchedulingResource $resource) => $resource->type === 'professional');
        if ($professionalResource) {
            throw ValidationException::withMessages([
                'resource_ids' => ['Profissionais devem ser informados em provider_id; resource_ids deve conter apenas recursos físicos ou operacionais.'],
            ]);
        }

        if ($itemIds === []) {
            return;
        }

        $eligibleResourceIds = DB::table('scheduling_resource_item')
            ->whereIn('scheduling_resource_id', $resourceIds)
            ->whereIn('item_id', $itemIds)
            ->pluck('scheduling_resource_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $unsupported = collect($resourceIds)->diff($eligibleResourceIds);
        if ($unsupported->isNotEmpty()) {
            throw ValidationException::withMessages([
                'resource_ids' => ['Um ou mais recursos selecionados não estão vinculados aos serviços deste agendamento.'],
            ]);
        }
    }
}
