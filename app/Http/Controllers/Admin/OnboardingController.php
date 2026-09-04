<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function __construct(private readonly AdminOnboardingService $onboarding) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_create') || $actor->hasPermission('application_manage')),
            403,
            'Você não tem permissão para realizar onboarding de clientes.'
        );

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'dry_run' => ['sometimes', 'boolean'],
            'user' => ['nullable', 'array'],
            'user.first_name' => ['nullable', 'string', 'max:100'],
            'user.last_name' => ['nullable', 'string', 'max:100'],
            'establishment' => ['nullable', 'array'],
            'establishment.name' => ['required_with:establishment', 'string', 'max:255'],
            'establishment.fantasy' => ['nullable', 'string', 'max:255'],
            'establishment.cnpj' => ['nullable', 'string', 'max:30'],
            'establishment.phone' => ['nullable', 'string', 'max:40'],
            'establishment.email' => ['nullable', 'email', 'max:255'],
            'establishment.description' => ['nullable', 'string', 'max:5000'],
            'establishment.category' => ['nullable', 'string', 'max:150'],
            'establishment.type' => ['nullable', 'string', 'max:100'],
            'establishment.city' => ['nullable', 'string', 'max:120'],
            'establishment.uf' => ['nullable', 'string', 'size:2'],
            'establishment.address' => ['nullable', 'string', 'max:500'],
            'establishment.cep' => ['nullable', 'string', 'max:20'],
            'establishment.is_published' => ['nullable', 'boolean'],
            'establishment.is_approved' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.type' => ['required', Rule::in(['service', 'product', 'item', 'ticket'])],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:5000'],
            'items.*.category' => ['nullable', 'string', 'max:150'],
            'items.*.subcategory' => ['nullable', 'string', 'max:150'],
            'items.*.brand' => ['nullable', 'string', 'max:150'],
            'items.*.duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'items.*.stock' => ['nullable', 'integer', 'min:0'],
            'items.*.status' => ['nullable', 'boolean'],
            'items.*.is_featured' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Informe o e-mail do cliente.',
            'email.email' => 'Informe um e-mail válido.',
            'app_id.required' => 'Selecione o aplicativo.',
            'app_id.exists' => 'O aplicativo selecionado não existe.',
            'establishment.name.required_with' => 'Informe o nome da empresa.',
            'establishment.uf.size' => 'A UF deve ter exatamente 2 caracteres.',
        ]);

        $result = $this->onboarding->execute($actor, $data);

        return response()->json($result['payload'], $result['status']);
    }
}
