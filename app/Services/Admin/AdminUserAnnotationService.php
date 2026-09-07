<?php

namespace App\Services\Admin;

use App\Models\AdminUserAnnotation;
use App\Models\EcosystemAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminUserAnnotationService
{
    public function storeNote(User $target, ?User $actor, array $data, Request $request): array
    {
        $note = AdminUserAnnotation::query()->create([
            'target_user_id' => $target->id,
            'actor_user_id' => $actor?->id,
            'kind' => 'note',
            'value' => trim($data['message']),
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);

        $this->audit($request, $target, 'user.admin_note.created', null, [
            'annotation_id' => $note->id,
            'is_pinned' => $note->is_pinned,
        ]);

        return $this->payload($note->load('actor:id,first_name,last_name,email'));
    }

    public function deleteNote(User $target, AdminUserAnnotation $note, Request $request): void
    {
        $before = [
            'annotation_id' => $note->id,
            'is_pinned' => $note->is_pinned,
            'value_sha256' => hash('sha256', $note->value),
        ];
        $note->delete();
        $this->audit($request, $target, 'user.admin_note.deleted', $before, null);
    }

    public function replaceTags(User $target, ?User $actor, array $tags, Request $request): array
    {
        $normalized = collect($tags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn ($tag) => mb_strtolower($tag))
            ->take(30)
            ->values();

        $before = AdminUserAnnotation::query()
            ->where('target_user_id', $target->id)
            ->where('kind', 'tag')
            ->pluck('value')
            ->values()
            ->all();

        DB::transaction(function () use ($target, $actor, $normalized) {
            AdminUserAnnotation::query()
                ->where('target_user_id', $target->id)
                ->where('kind', 'tag')
                ->delete();

            foreach ($normalized as $tag) {
                AdminUserAnnotation::query()->create([
                    'target_user_id' => $target->id,
                    'actor_user_id' => $actor?->id,
                    'kind' => 'tag',
                    'value' => $tag,
                ]);
            }
        });

        $after = $normalized->all();
        $this->audit($request, $target, 'user.tags.updated', ['tags' => $before], ['tags' => $after]);

        return $after;
    }

    private function payload(AdminUserAnnotation $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'value' => $row->value,
            'is_pinned' => (bool) $row->is_pinned,
            'actor' => $row->actor ? [
                'id' => $row->actor->id,
                'name' => trim($row->actor->first_name . ' ' . $row->actor->last_name) ?: $row->actor->email,
                'email' => $row->actor->email,
            ] : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function audit(Request $request, User $target, string $action, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;

        EcosystemAuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $target->id,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
