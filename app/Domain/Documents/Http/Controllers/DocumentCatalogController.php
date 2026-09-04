<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DocumentCatalogController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function templates()
    {
        $appId = $this->context->id();
        return response()->json(DB::table('document_templates')
            ->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $appId))
            ->where('status', 'active')->orderBy('name')->get()->map(function ($template) {
                $template->metadata = $this->decode($template->metadata);
                $template->latest_version = DB::table('document_template_versions')->where('template_id', $template->id)->orderByDesc('version')->first();
                if ($template->latest_version) $template->latest_version->variables = $this->decode($template->latest_version->variables);
                return $template;
            }));
    }

    public function storeTemplate(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate(['key' => 'required|string|max:120', 'name' => 'required|string|max:190', 'document_type' => 'required|string|max:80', 'content' => 'required|string|max:200000', 'variables' => 'nullable|array', 'metadata' => 'nullable|array']);
        $id = DB::transaction(function () use ($request, $data) {
            $id = DB::table('document_templates')->insertGetId(['app_id' => $this->context->id(), 'key' => $data['key'], 'name' => $data['name'], 'document_type' => $data['document_type'], 'status' => 'active', 'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('document_template_versions')->insert(['template_id' => $id, 'version' => 1, 'content' => $data['content'], 'variables' => $this->json($data['variables'] ?? []), 'created_by_user_id' => $request->user()->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            return $id;
        });
        return response()->json(DB::table('document_templates')->find($id), 201);
    }

    public function addTemplateVersion(Request $request, int $templateId)
    {
        $this->assertAdmin($request);
        $template = DB::table('document_templates')->where('id', $templateId)->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $this->context->id()))->firstOrFail();
        $data = $request->validate(['content' => 'required|string|max:200000', 'variables' => 'nullable|array', 'publish' => 'sometimes|boolean']);
        $next = ((int) DB::table('document_template_versions')->where('template_id', $template->id)->max('version')) + 1;
        $id = DB::table('document_template_versions')->insertGetId(['template_id' => $template->id, 'version' => $next, 'content' => $data['content'], 'variables' => $this->json($data['variables'] ?? []), 'created_by_user_id' => $request->user()->id, 'published_at' => ($data['publish'] ?? true) ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('document_template_versions')->find($id), 201);
    }

    public function clauses()
    {
        $appId = $this->context->id();
        return response()->json(DB::table('document_clauses')->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $appId))->where('is_active', true)->orderBy('category')->orderBy('name')->get()->map(function ($clause) { $clause->rules = $this->decode($clause->rules); return $clause; }));
    }

    public function storeClause(Request $request)
    {
        $this->assertAdmin($request); $data = $this->validateClause($request);
        $id = DB::table('document_clauses')->insertGetId(['app_id' => $this->context->id(), 'key' => $data['key'], 'name' => $data['name'], 'category' => $data['category'] ?? 'general', 'content' => $data['content'], 'rules' => $this->json($data['rules'] ?? null), 'is_required' => $data['is_required'] ?? false, 'is_active' => $data['is_active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('document_clauses')->find($id), 201);
    }

    public function updateClause(Request $request, int $clauseId)
    {
        $this->assertAdmin($request); DB::table('document_clauses')->where('id', $clauseId)->where('app_id', $this->context->id())->firstOrFail(); $data = $this->validateClause($request, true);
        $updates = collect($data)->mapWithKeys(fn ($value, $key) => [$key => $key === 'rules' ? $this->json($value) : $value])->all(); $updates['updated_at'] = now();
        DB::table('document_clauses')->where('id', $clauseId)->update($updates); return response()->json(DB::table('document_clauses')->find($clauseId));
    }

    private function validateClause(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        return $request->validate(['key' => $required.'|string|max:120', 'name' => $required.'|string|max:190', 'category' => 'sometimes|string|max:80', 'content' => $required.'|string|max:50000', 'rules' => 'nullable|array', 'is_required' => 'sometimes|boolean', 'is_active' => 'sometimes|boolean']);
    }

    private function assertAdmin(Request $request): void { abort_unless(method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'), 403, 'Apenas administradores podem alterar o catálogo documental.'); }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(mixed $value): mixed { if (! $value || is_array($value)) return $value ?: []; return json_decode((string) $value, true) ?: []; }
}
