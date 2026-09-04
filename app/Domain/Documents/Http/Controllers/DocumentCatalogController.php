<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Services\DocumentCatalogService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class DocumentCatalogController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DocumentCatalogService $catalog,
    ) {}

    public function templates()
    {
        return response()->json($this->catalog->templates($this->context->id()));
    }

    public function storeTemplate(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'key' => 'required|string|max:120',
            'name' => 'required|string|max:190',
            'document_type' => 'required|string|max:80',
            'content' => 'required|string|max:200000',
            'variables' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $template = $this->catalog->createTemplate($this->context->id(), (int) $request->user()->id, $data);

        return response()->json($template, 201);
    }

    public function addTemplateVersion(Request $request, int $templateId)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'content' => 'required|string|max:200000',
            'variables' => 'nullable|array',
            'publish' => 'sometimes|boolean',
        ]);

        $version = $this->catalog->addTemplateVersion(
            $this->context->id(),
            $templateId,
            (int) $request->user()->id,
            $data,
        );

        return response()->json($version, 201);
    }

    public function clauses()
    {
        return response()->json($this->catalog->clauses($this->context->id()));
    }

    public function storeClause(Request $request)
    {
        $this->assertAdmin($request);
        $clause = $this->catalog->createClause($this->context->id(), $this->validateClause($request));

        return response()->json($clause, 201);
    }

    public function updateClause(Request $request, int $clauseId)
    {
        $this->assertAdmin($request);
        $clause = $this->catalog->updateClause($this->context->id(), $clauseId, $this->validateClause($request, true));

        return response()->json($clause);
    }

    private function validateClause(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'key' => $required.'|string|max:120',
            'name' => $required.'|string|max:190',
            'category' => 'sometimes|string|max:80',
            'content' => $required.'|string|max:50000',
            'rules' => 'nullable|array',
            'is_required' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless(
            method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'),
            403,
            'Apenas administradores podem alterar o catálogo documental.'
        );
    }
}
