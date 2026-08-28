<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ServiceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ServiceRecordController extends Controller
{
    public function store(Request $request)
    {
        if (! $this->allowed('service_record_store')) {
            return response()->json(['error' => 'Você não tem permissão para registrar atendimentos.'], 403);
        }

        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'entity_name' => 'required|string|max:100',
            'entity_id' => 'required|integer|min:1',
            'service_ids' => 'required|array|min:1',
            'service_ids.*' => 'integer|distinct|exists:items,id',
            'provider_id' => 'required|integer|exists:users,id',
            'client_id' => 'required|integer|exists:users,id',
            'discount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|max:50',
            'total_price' => 'required|numeric|min:0',
            'status' => ['required', 'string', Rule::in($this->statuses())],
            'notes' => 'nullable|string|max:5000',
        ]);

        $serviceRecord = ServiceRecord::create(array_merge($data, [
            'registered_by' => Auth::id(),
            'discount' => $data['discount'] ?? 0,
        ]));

        return response()->json([
            'message' => 'Atendimento registrado com sucesso.',
            'service_record' => $this->withServices($serviceRecord->load(['provider', 'client', 'registeredBy'])),
        ], 201);
    }

    public function listMy(Request $request)
    {
        $perPage = $this->perPage($request);

        $records = ServiceRecord::query()
            ->where(function ($query) {
                $query->where('client_id', Auth::id())
                    ->orWhere('provider_id', Auth::id())
                    ->orWhere('registered_by', Auth::id());
            })
            ->with(['provider', 'client', 'registeredBy'])
            ->latest()
            ->paginate($perPage);

        $records->getCollection()->transform(fn ($record) => $this->withServices($record));

        return response()->json($records);
    }

    public function listByClient(Request $request)
    {
        if (! $this->allowed('service_record_list')) {
            return response()->json(['error' => 'Você não tem permissão para listar atendimentos.'], 403);
        }

        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'entity_name' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer|min:1',
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $query = ServiceRecord::query()
            ->where('client_id', $data['client_id'])
            ->with(['provider', 'client', 'registeredBy'])
            ->latest();

        $this->applyEntityFilters($query, $data);

        return $this->paginatedWithServices($query, $request);
    }

    public function listByEntity(Request $request)
    {
        if (! $this->allowed('service_record_list')) {
            return response()->json(['error' => 'Você não tem permissão para listar atendimentos.'], 403);
        }

        $data = $request->validate([
            'entity_name' => 'required|string|max:100',
            'entity_id' => 'required|integer|min:1',
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $query = ServiceRecord::query()
            ->where('entity_name', $data['entity_name'])
            ->where('entity_id', $data['entity_id'])
            ->with(['provider', 'client', 'registeredBy'])
            ->latest();

        if (isset($data['app_id'])) {
            $query->where('app_id', $data['app_id']);
        }

        return $this->paginatedWithServices($query, $request);
    }

    public function listByProvider(Request $request)
    {
        if (! $this->allowed('service_record_list')) {
            return response()->json(['error' => 'Você não tem permissão para listar atendimentos.'], 403);
        }

        $data = $request->validate([
            'provider_id' => 'required|integer|exists:users,id',
            'entity_name' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer|min:1',
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $query = ServiceRecord::query()
            ->where('provider_id', $data['provider_id'])
            ->with(['provider', 'client', 'registeredBy'])
            ->latest();

        $this->applyEntityFilters($query, $data);

        return $this->paginatedWithServices($query, $request);
    }

    public function updateStatus(Request $request, $id)
    {
        if (! $this->allowed('service_record_update')) {
            return response()->json(['error' => 'Você não tem permissão para atualizar atendimentos.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->statuses())],
        ]);

        $record = ServiceRecord::findOrFail($id);
        $record->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Status do atendimento atualizado com sucesso.',
            'service_record' => $record->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $record = ServiceRecord::findOrFail($id);

        $ownsRecord = in_array((int) Auth::id(), [
            (int) $record->registered_by,
            (int) $record->provider_id,
        ], true);

        if (! $ownsRecord && ! $this->allowed('service_record_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir este atendimento.'], 403);
        }

        $record->delete();

        return response()->json(['message' => 'Atendimento excluído com sucesso.']);
    }

    private function applyEntityFilters($query, array $data): void
    {
        foreach (['entity_name', 'entity_id', 'app_id'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $query->where($field, $data[$field]);
            }
        }
    }

    private function paginatedWithServices($query, Request $request)
    {
        $records = $query->paginate($this->perPage($request));
        $records->getCollection()->transform(fn ($record) => $this->withServices($record));

        return response()->json($records);
    }

    private function withServices(ServiceRecord $record): ServiceRecord
    {
        $ids = is_array($record->service_ids) ? $record->service_ids : [];
        $record->setAttribute('services', $ids === []
            ? collect()
            : Item::query()->whereIn('id', $ids)->get());

        return $record;
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->input('per_page', 25), 100));
    }

    private function allowed(string $permission): bool
    {
        $user = Auth::user();

        return $user && ($user->hasProfile('Administrador') || $user->hasPermission($permission));
    }

    private function statuses(): array
    {
        return ['pending', 'approved', 'not-approved', 'completed', 'cancelled'];
    }
}
