<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Interaction;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OrganizationExperienceController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function publicExperience(Request $request, string $slug)
    {
        $organization = Production::query()->where('app_id', $this->context->id())->where('slug', $slug)
            ->where('is_published', true)->where('is_cancelled', false)->firstOrFail();
        Interaction::registerView($organization, $this->optionalUser($request), ['surface' => 'organization_public']);
        return response()->json(['analytics' => $this->analytics($organization), 'media' => $this->media($organization)]);
    }

    public function workspace(Request $request, int $id)
    {
        $organization = $this->managed($request, $id);
        $appId = $this->context->id();
        $events = Event::query()->where('app_id', $appId)->where('production_id', $organization->id)
            ->orderByDesc('start_date')->limit(60)->get();
        $organization->setAttribute('events_count', $events->count());
        $organization->setAttribute('followers_count', DB::table('follows')->where([
            'app_id' => $appId, 'target_type' => 'production', 'target_id' => $organization->id,
        ])->count());
        return response()->json([
            'organization' => $organization,
            'events' => $events,
            'analytics' => $this->analytics($organization),
            'media' => $this->media($organization),
        ]);
    }

    public function updateProfile(Request $request, int $id)
    {
        $organization = $this->managed($request, $id);
        $data = $request->validate([
            'type' => 'sometimes|nullable|in:fixed,independent',
            'city_id' => 'sometimes|nullable|integer', 'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2', 'cep' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255', 'address_number' => 'sometimes|nullable|string|max:30',
            'neighborhood' => 'sometimes|nullable|string|max:160', 'address_complement' => 'sometimes|nullable|string|max:255',
            'address_reference' => 'sometimes|nullable|string|max:255', 'formatted_address' => 'sometimes|nullable|string|max:700',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'place_id' => 'sometimes|nullable|string|max:255', 'google_maps_url' => 'sometimes|nullable|url:http,https|max:2048',
            'location_public' => 'sometimes|boolean',
        ]);
        if (array_key_exists('uf', $data) && $data['uf']) $data['uf'] = strtoupper(trim((string) $data['uf']));
        if (array_key_exists('cep', $data) && $data['cep']) $data['cep'] = preg_replace('/\D+/', '', (string) $data['cep']);
        foreach (['city','address','address_number','neighborhood','address_complement','address_reference','formatted_address','place_id','google_maps_url','type'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) $data[$field] = trim($data[$field]) ?: null;
        }
        $organization->update($data);
        return response()->json(['message' => 'Perfil da produção atualizado.', 'organization' => $organization->fresh()]);
    }

    private function analytics(Production $organization): array
    {
        $base = Interaction::query()->where('app_id', $this->context->id())
            ->where('entity_type', 'Production')->where('entity_id', $organization->id)->where('interaction_type', 'view');
        $rows = (clone $base)->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as views_count, MAX(created_at) as last_viewed_at')
            ->groupBy('user_id')->orderByDesc('last_viewed_at')->limit(100)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id','first_name','last_name','avatar'])->keyBy('id');
        $viewers = $rows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);
            if (! $user) return null;
            return [
                'id' => (int) $user->id,
                'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Participante',
                'avatar' => $user->avatar,
                'views_count' => (int) $row->views_count,
                'last_viewed_at' => $row->last_viewed_at,
            ];
        })->filter()->values();
        return [
            'total_views' => (clone $base)->count(),
            'unique_viewers' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
            'viewers' => $viewers,
        ];
    }

    private function media(Production $organization): array
    {
        return DB::table('organization_media')->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'url' => Storage::disk('public')->url($row->path),
                'caption' => $row->caption,
                'position' => (int) $row->position,
            ])->all();
    }

    private function managed(Request $request, int $id): Production
    {
        $organization = Production::query()->where('app_id', $this->context->id())->findOrFail($id);
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $organization->user_id === (int) $user->id), 403, 'Você não pode gerenciar esta organização.');
        return $organization;
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) return null;
        try { $user = JWTAuth::setToken($token)->authenticate(); return $user instanceof User ? $user : null; } catch (Throwable) { return null; }
    }
}
