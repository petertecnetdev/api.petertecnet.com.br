<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Engagement\Services\EstablishmentEngagementCommunicationService;
use App\Domain\Platform\Services\ApplicationAdminEstablishmentService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationAdminProductionController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ApplicationAdminEstablishmentService $establishments,
        private readonly EstablishmentEngagementCommunicationService $communications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:published,draft,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $this->establishments->paginateProductions($this->context->id(), $data),
        ]);
    }

    public function update(Request $request, int $production): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $this->establishments->updateProduction($this->context->id(), $production, $data),
        ]);
    }

    public function engagementPreview(Request $request, int $production): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->communications->preview($this->context->id(), $production),
        ]);
    }

    public function sendEngagementEmail(Request $request, int $production): JsonResponse
    {
        $actor = $request->user('api') ?? $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Resumo enviado ao produtor com sucesso.',
            'data' => $this->communications->send(
                applicationId: $this->context->id(),
                establishmentId: $production,
                actorId: $actor?->id,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            ),
        ], 202);
    }
}
