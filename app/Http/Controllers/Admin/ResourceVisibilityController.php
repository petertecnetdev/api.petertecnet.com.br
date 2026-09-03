<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Interaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ResourceVisibilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['public', 'hidden', 'pending_approval', 'disabled'])],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:300'],
        ]);

        $query = Establishment::query()
            ->with([
                'app:id,name,slug',
                'applications:id,name,slug',
                'user:id,first_name,last_name,user_name,email',
            ])
            ->withCount(['items as items_count']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('fantasy', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('uf', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['app_id'])) {
            $query->forApplication((int) $filters['app_id']);
        }

        match ($filters['status'] ?? null) {
            'public' => $query->where('is_cancelled', false)->where('is_published', true)->where('is_approved', true),
            'hidden' => $query->where('is_cancelled', false)->where('is_published', false),
            'pending_approval' => $query->where('is_cancelled', false)->where('is_published', true)->where('is_approved', false),
            'disabled' => $query->where('is_cancelled', true),
            default => null,
        };

        $limit = (int) ($filters['limit'] ?? 200);
        $establishments = $query->latest('updated_at')->limit($limit)->get();
        $ids = $establishments->pluck('id');
        $since = now()->subDays(30);

        $restricted = Interaction::query()
            ->where('entity_type', 'Establishment')
            ->whereIn('entity_id', $ids)
            ->where('interaction_type', 'restricted_access')
            ->selectRaw('entity_id, COUNT(*) total, MAX(created_at) last_at')
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');

        $restricted30d = Interaction::query()
            ->where('entity_type', 'Establishment')
            ->whereIn('entity_id', $ids)
            ->where('interaction_type', 'restricted_access')
            ->where('created_at', '>=', $since)
            ->selectRaw('entity_id, COUNT(*) total')
            ->groupBy('entity_id')
            ->pluck('total', 'entity_id');

        $rows = $establishments->map(function (Establishment $establishment) use ($restricted, $restricted30d) {
            $visibility = $this->visibilityState($establishment);
            $attempt = $restricted->get($establishment->id);

            return [
                'id' => $establishment->id,
                'name' => $establishment->fantasy ?: $establishment->name,
                'legal_name' => $establishment->name,
                'slug' => $establishment->slug,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
                'owner' => $establishment->user,
                'source_app' => $establishment->app,
                'applications' => $establishment->applications,
                'application_ids' => $establishment->applications->pluck('id')->map(fn ($id) => (int) $id)->values(),
                'items_count' => (int) $establishment->items_count,
                'is_published' => (bool) $establishment->is_published,
                'is_approved' => (bool) $establishment->is_approved,
                'is_cancelled' => (bool) $establishment->is_cancelled,
                'visibility' => $visibility,
                'restricted_access_attempts' => (int) ($attempt?->total ?? 0),
                'restricted_access_attempts_30d' => (int) ($restricted30d[$establishment->id] ?? 0),
                'restricted_access_last_at' => $attempt?->last_at,
                'updated_at' => $establishment->updated_at,
            ];
        })->values();

        $recentAttempts = Interaction::query()
            ->where('entity_type', 'Establishment')
            ->where('interaction_type', 'restricted_access')
            ->with([
                'user:id,first_name,last_name,user_name,email',
                'application:id,name,slug',
            ])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (Interaction $interaction) => [
                'id' => $interaction->id,
                'establishment_id' => $interaction->entity_id,
                'application' => $interaction->application,
                'user' => $interaction->user,
                'reason' => data_get($interaction->content, 'availability_reason'),
                'resource' => data_get($interaction->content, 'resource'),
                'created_at' => $interaction->created_at,
            ]);

        return response()->json([
            'summary' => [
                'total' => Establishment::count(),
                'public' => Establishment::where('is_cancelled', false)->where('is_published', true)->where('is_approved', true)->count(),
                'hidden' => Establishment::where('is_cancelled', false)->where('is_published', false)->count(),
                'pending_approval' => Establishment::where('is_cancelled', false)->where('is_published', true)->where('is_approved', false)->count(),
                'disabled' => Establishment::where('is_cancelled', true)->count(),
                'restricted_access_attempts_30d' => Interaction::query()
                    ->where('entity_type', 'Establishment')
                    ->where('interaction_type', 'restricted_access')
                    ->where('created_at', '>=', $since)
                    ->count(),
            ],
            'establishments' => $rows,
            'recent_restricted_access' => $recentAttempts,
        ]);
    }

    private function visibilityState(Establishment $establishment): array
    {
        if ($establishment->is_cancelled) {
            return ['status' => 'disabled', 'reason' => 'disabled', 'label' => 'Desativado', 'indexable' => false];
        }

        if (! $establishment->is_published) {
            return ['status' => 'hidden', 'reason' => 'not_public', 'label' => 'Oculto', 'indexable' => false];
        }

        if (! $establishment->is_approved) {
            return ['status' => 'pending_approval', 'reason' => 'pending_approval', 'label' => 'Aguardando aprovação', 'indexable' => false];
        }

        return ['status' => 'public', 'reason' => null, 'label' => 'Público', 'indexable' => true];
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        ), 403, 'Usuário sem permissão para administrar o ecossistema Peter Tecnet.');
    }
}
