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
        private readonly EstablishmentEngagementCommunicationService $engagement,
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
            'data' => $this->establishments->paginate($this->context->id(), 'production', $data),
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

        $updated = $this->establishments->update(
            $this->context->id(),
            'production',
            $production,
            $data,
            $request->user('api') ?? $request->user(),
            $this->auditContext($request),
        );

        return response()->json(['success'=>true,'scope'=>'global_application','data'=>$updated]);
    }

    public function engagementPreview(Request $request, int $production): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->engagement->preview($this->context->id(), $production),
        ]);
    }

    public function sendEngagementEmail(Request $request, int $production): JsonResponse
    {
        $data = $this->engagement->send(
            $this->context->id(),
            $production,
            $request->user('api')?->id ?? $request->user()?->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Resumo enviado ao produtor com sucesso.',
            'data' => $data,
        ], 202);
    }

    private function auditContext(Request $request): array
    {
        return [
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'authority' => $request->attributes->get('admin_authority'),
            'scope' => $request->attributes->get('admin_scope'),
        ];
    }
}
