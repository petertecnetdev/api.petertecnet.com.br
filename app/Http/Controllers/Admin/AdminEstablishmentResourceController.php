<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Services\AdminEstablishmentResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEstablishmentResourceController extends Controller
{
    public function __construct(private readonly AdminEstablishmentResourceService $resources) {}

    public function context(Request $request, Establishment $establishment): JsonResponse
    {
        $this->resources->authorizeAccess($request);
        $data = $request->validate(['app_id' => ['nullable', 'integer', 'exists:applications,id']]);
        $appId = (int) ($data['app_id'] ?? $establishment->app_id);
        return response()->json($this->resources->context($establishment, $appId));
    }

    public function storeEmployer(Request $request, Establishment $establishment): JsonResponse
    {
        $this->resources->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'max:255'],
            'permissions' => ['nullable', 'array', 'max:100'],
            'permissions.*' => ['string', 'max:100'],
        ]);
        return response()->json([
            'message' => 'Employer criado com sucesso pelo Admin Center.',
            'employer' => $this->resources->storeEmployer($request, $establishment, $data),
        ], 201);
    }

    public function storeAppointment(Request $request, Establishment $establishment): JsonResponse
    {
        $this->resources->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'attendant_id' => ['required', 'integer', 'exists:employers,id'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'order_datetime' => ['required', 'date', 'after:now'],
            'payment_method' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'client_id.required' => 'Selecione o cliente do agendamento.',
            'attendant_id.required' => 'Selecione o employer que realizará o atendimento.',
            'items.required' => 'Selecione pelo menos um item ou serviço.',
            'order_datetime.after' => 'O agendamento precisa estar no futuro.',
        ]);
        return $this->resources->storeAppointment($request, $establishment, $data);
    }
}
