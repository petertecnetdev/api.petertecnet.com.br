<?php

namespace App\Domain\Documents\Services;

use Illuminate\Support\Facades\DB;

final class DocumentCatalogService
{
    public function templates(int $appId)
    {
        return DB::table('document_templates')
            ->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $appId))
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function ($template) {
                $template->metadata = $this->decode($template->metadata);
                $template->latest_version = DB::table('document_template_versions')
                    ->where('template_id', $template->id)
                    ->orderByDesc('version')
                    ->first();
                if ($template->latest_version) {
                    $template->latest_version->variables = $this->decode($template->latest_version->variables);
                }
                return $template;
            });
    }

    public function createTemplate(int $appId, int $actorUserId, array $data): object
    {
        $id = DB::transaction(function () use ($appId, $actorUserId, $data) {
            $id = DB::table('document_templates')->insertGetId([
                'app_id' => $appId,
                'key' => $data['key'],
                'name' => $data['name'],
                'document_type' => $data['document_type'],
                'status' => 'active',
                'metadata' => $this->json($data['metadata'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('document_template_versions')->insert([
                'template_id' => $id,
                'version' => 1,
                'content' => $data['content'],
                'variables' => $this->json($data['variables'] ?? []),
                'created_by_user_id' => $actorUserId,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });

        return DB::table('document_templates')->find($id);
    }

    public function addTemplateVersion(int $appId, int $templateId, int $actorUserId, array $data): object
    {
        return DB::transaction(function () use ($appId, $templateId, $actorUserId, $data) {
            $template = DB::table('document_templates')
                ->where('id', $templateId)
                ->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $appId))
                ->lockForUpdate()
                ->firstOrFail();

            $next = ((int) DB::table('document_template_versions')
                ->where('template_id', $template->id)
                ->max('version')) + 1;

            $id = DB::table('document_template_versions')->insertGetId([
                'template_id' => $template->id,
                'version' => $next,
                'content' => $data['content'],
                'variables' => $this->json($data['variables'] ?? []),
                'created_by_user_id' => $actorUserId,
                'published_at' => ($data['publish'] ?? true) ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('document_template_versions')->find($id);
        });
    }

    public function clauses(int $appId)
    {
        return DB::table('document_clauses')
            ->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $appId))
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(function ($clause) {
                $clause->rules = $this->decode($clause->rules);
                return $clause;
            });
    }

    public function createClause(int $appId, array $data): object
    {
        $id = DB::table('document_clauses')->insertGetId([
            'app_id' => $appId,
            'key' => $data['key'],
            'name' => $data['name'],
            'category' => $data['category'] ?? 'general',
            'content' => $data['content'],
            'rules' => $this->json($data['rules'] ?? null),
            'is_required' => $data['is_required'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('document_clauses')->find($id);
    }

    public function updateClause(int $appId, int $clauseId, array $data): object
    {
        DB::transaction(function () use ($appId, $clauseId, $data) {
            DB::table('document_clauses')
                ->where('id', $clauseId)
                ->where('app_id', $appId)
                ->lockForUpdate()
                ->firstOrFail();

            $updates = collect($data)->mapWithKeys(fn ($value, $key) => [
                $key => $key === 'rules' ? $this->json($value) : $value,
            ])->all();
            $updates['updated_at'] = now();

            DB::table('document_clauses')->where('id', $clauseId)->where('app_id', $appId)->update($updates);
        });

        return DB::table('document_clauses')->where('id', $clauseId)->where('app_id', $appId)->firstOrFail();
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): mixed
    {
        if (! $value || is_array($value)) {
            return $value ?: [];
        }

        return json_decode((string) $value, true) ?: [];
    }
}
