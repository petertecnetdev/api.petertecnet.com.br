<?php

namespace App\Services\Admin;

use App\Models\EcosystemAuditLog;
use App\Models\Interaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InteractionMaintenanceService
{
    private const PURGE_CONFIRMATION = 'LIMPAR TODAS AS INTERAÇÕES';

    public function destroySelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'ids.required' => 'Selecione pelo menos uma interação.',
            'ids.min' => 'Selecione pelo menos uma interação.',
            'ids.max' => 'É possível excluir no máximo 500 interações por operação.',
        ]);

        $ids = collect($validated['ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $deleted = DB::transaction(function () use ($request, $ids): int {
            $deleted = Interaction::query()
                ->whereIn('id', $ids->all())
                ->delete();

            $this->auditCleanup(
                $request,
                'interactions.delete_selected',
                [
                    'requested_count' => $ids->count(),
                    'interaction_ids' => $ids->all(),
                ],
                [
                    'deleted_count' => $deleted,
                ]
            );

            return $deleted;
        });

        return response()->json([
            'message' => $deleted === 1
                ? 'Interação excluída com sucesso.'
                : 'Interações excluídas com sucesso.',
            'requested' => $ids->count(),
            'deleted' => $deleted,
            'remaining' => Interaction::query()->count(),
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([self::PURGE_CONFIRMATION])],
        ], [
            'confirmation.required' => 'Confirme explicitamente a limpeza de todas as interações.',
            'confirmation.in' => 'A frase de confirmação não confere. Nenhuma interação foi excluída.',
        ]);

        $totalBefore = Interaction::query()->count();

        $deleted = DB::transaction(function () use ($request, $totalBefore): int {
            $deleted = Interaction::query()->delete();

            $this->auditCleanup(
                $request,
                'interactions.purge_all',
                [
                    'total_before' => $totalBefore,
                ],
                [
                    'deleted_count' => $deleted,
                ]
            );

            return $deleted;
        });

        return response()->json([
            'message' => 'Todas as interações armazenadas foram excluídas.',
            'deleted' => $deleted,
            'remaining' => Interaction::query()->count(),
        ]);
    }

    private function auditCleanup(Request $request, string $action, array $before, array $after): void
    {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => Interaction::class,
            'entity_id' => null,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
