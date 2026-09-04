<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OrganizationController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function publicIndex(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'radius_km' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $appId = $this->context->id();
        $query = $this->productionEstablishments()
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->select('establishments.*')
            ->selectSub(
                Event::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('events.establishment_id', 'establishments.id')
                    ->where('events.app_id', $appId)
                    ->where('events.is_published', true)
                    ->where('events.is_cancelled', false)
                    ->where(fn ($privacy) => $privacy->where('events.is_private', false)->orWhereNull('events.is_private'))
                    ->where('events.end_date', '>', now()),
                'upcoming_events_count'
            );

        if (! empty($data['city'])) $query->whereRaw('LOWER(city) = LOWER(?)', [trim($data['city'])]);
        if (! empty($data['uf'])) $query->where('uf', strtoupper($data['uf']));

        if (isset($data['lat'], $data['lng'])) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $radius = (int) ($data['radius_km'] ?? 80);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';
            $query->whereNotNull('latitude')->whereNotNull('longitude')
                ->selectRaw("{$distanceSql} AS distance_km", [$lat, $lng, $lat])
                ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius])
                ->orderBy('distance_km');
        } else {
            $query->orderByDesc('is_featured')->orderByDesc('upcoming_events_count')->orderBy('name');
        }

        return response()->json(['organizations' => $query->paginate($data['per_page'] ?? 12)->appends($request->query())]);
    }

    public function publicShow(Request $request, string $slug)
    {
        $appId = $this->context->id();
        $organization = $this->productionEstablishments()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $organization->setAttribute('followers_count', DB::table('follows')->where([
            'app_id' => $appId, 'target_type' => 'establishment', 'target_id' => $organization->id,
        ])->count());
        $user = $this->optionalRequestUser($request);
        $organization->setAttribute('is_following', $user ? DB::table('follows')->where([
            'app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'establishment', 'target_id' => $organization->id,
        ])->exists() : false);

        $visibleEvents = Event::query()->where('app_id', $appId)->where('establishment_id', $organization->id)
            ->where('is_published', true)->where('is_cancelled', false)
            ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'));
        $upcoming = (clone $visibleEvents)->where('end_date', '>', now())->orderBy('start_date')->limit(24)->get();
        $past = (clone $visibleEvents)->where('end_date', '<=', now())->orderByDesc('start_date')->limit(24)->get();
        $artists = Artist::query()->where('app_id', $appId)->where('is_published', true)
            ->whereHas('events', fn ($q) => $q->where('events.app_id', $appId)->where('events.establishment_id', $organization->id)->where('events.is_published', true)->where('events.is_cancelled', false))
            ->distinct()->limit(30)->get();

        return response()->json(compact('organization', 'upcoming', 'past', 'artists'));
    }

    public function mine(Request $request)
    {
        $appId = $this->context->id();
        $organizations = $this->productionEstablishments()
            ->where('user_id', $request->user()->id)
            ->select('establishments.*')
            ->selectSub(
                Event::query()->selectRaw('COUNT(*)')
                    ->whereColumn('events.establishment_id', 'establishments.id')
                    ->where('events.app_id', $appId),
                'events_count'
            )
            ->latest()
            ->get();

        return response()->json(['organizations' => $organizations]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['organization' => $this->owned($request, $id)]);
    }

    public function store(Request $request)
    {
        $this->normalize($request);
        $data = $request->validate($this->rules(true));
        $user = $request->user();
        $data['user_id'] = $user->id;
        $data['app_id'] = $this->context->id();
        $data['type'] = 'production';
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['is_published'] = true;
        $data['is_cancelled'] = false;
        unset($data['logo'], $data['background']);

        $organization = Establishment::create($data);
        $this->storeImages($request, $organization);
        $this->context->application()->users()->syncWithoutDetaching([
            $user->id => ['role' => 'producer', 'status' => 'active', 'joined_at' => now()],
        ]);

        return response()->json(['message' => 'Estabelecimento criado com sucesso.', 'organization' => $organization->fresh()], 201);
    }

    public function update(Request $request, int $id)
    {
        $organization = $this->owned($request, $id);
        $this->normalize($request);
        $data = $request->validate($this->rules(false));
        if (! empty($data['name']) && $data['name'] !== $organization->name) $data['slug'] = $this->uniqueSlug($data['name'], $organization->id);
        unset($data['logo'], $data['background'], $data['user_id'], $data['app_id'], $data['type']);
        $organization->update($data);
        $this->storeImages($request, $organization);
        return response()->json(['message' => 'Estabelecimento atualizado com sucesso.', 'organization' => $organization->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $organization = $this->owned($request, $id);
        $hasIssuedPasses = Event::query()
            ->where('app_id', $this->context->id())
            ->where('establishment_id', $organization->id)
            ->whereHas('tickets.passes')
            ->exists();
        abort_if($hasIssuedPasses, 409, 'Estabelecimentos com ingressos emitidos não podem ser excluídos.');

        DB::transaction(function () use ($organization) {
            Event::query()
                ->where('app_id', $this->context->id())
                ->where('establishment_id', $organization->id)
                ->update([
                    'is_published' => false,
                    'is_cancelled' => true,
                    'updated_at' => now(),
                ]);

            $organization->update([
                'is_published' => false,
                'is_cancelled' => true,
            ]);
            $organization->delete();
        });

        return response()->json(['message' => 'Estabelecimento excluído com sucesso.']);
    }

    private function owned(Request $request, int $id): Establishment
    {
        $organization = $this->productionEstablishments()->findOrFail($id);
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $organization->user_id === (int) $user->id), 403, 'Você não pode gerenciar este estabelecimento.');
        return $organization;
    }

    private function productionEstablishments()
    {
        return Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'production');
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return [
            'name' => $required . 'string|min:2|max:255', 'fantasy' => 'sometimes|nullable|string|max:255',
            'cnpj' => ['sometimes','nullable','regex:/^\d{14}$/'], 'phone' => 'sometimes|nullable|string|max:30',
            'description' => 'sometimes|nullable|string|max:10000', 'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2', 'address' => 'sometimes|nullable|string|max:255',
            'website_url' => 'sometimes|nullable|url:http,https|max:2048', 'instagram_url' => 'sometimes|nullable|url:http,https|max:2048',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function normalize(Request $request): void
    {
        $merge = [];
        foreach (['name','fantasy','phone','description','city','address'] as $field) if ($request->exists($field)) $merge[$field] = trim((string) $request->input($field));
        if ($request->exists('uf')) $merge['uf'] = strtoupper(trim((string) $request->input('uf')));
        if ($request->filled('cnpj')) $merge['cnpj'] = preg_replace('/\D+/', '', (string) $request->input('cnpj'));

        if ($request->exists('website_url')) {
            $value = trim((string) $request->input('website_url'));
            $merge['website_url'] = $value === '' ? null : (preg_match('#^https?://#i', $value) ? $value : 'https://' . ltrim($value, '/'));
        }

        if ($request->exists('instagram_url')) {
            $value = trim((string) $request->input('instagram_url'));
            if ($value === '') {
                $merge['instagram_url'] = null;
            } elseif (preg_match('#^https?://#i', $value)) {
                $merge['instagram_url'] = $value;
            } else {
                $handle = ltrim($value, '@/');
                $merge['instagram_url'] = str_contains($handle, 'instagram.com/')
                    ? 'https://' . $handle
                    : 'https://instagram.com/' . $handle;
            }
        }

        if ($merge) $request->merge($merge);
    }

    private function storeImages(Request $request, Establishment $organization): void
    {
        foreach (['logo' => [600,600], 'background' => [1920,700]] as $field => $size) {
            if (! $request->hasFile($field)) continue;
            if ($organization->{$field} && str_starts_with($organization->{$field}, 'images/apps/')) Storage::disk('public')->delete($organization->{$field});
            $path = 'images/apps/' . $this->context->slug() . '/establishments/' . $field . '-' . Str::uuid() . '.webp';
            $absolute = Storage::disk('public')->path($path);
            if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0755, true);
            Image::make($request->file($field)->getRealPath())->orientate()->fit($size[0], $size[1])->encode('webp', 86)->save($absolute);
            $organization->{$field} = $path;
        }
        if ($organization->isDirty(['logo','background'])) $organization->save();
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'estabelecimento-' . Str::lower(Str::random(8)); $slug = $base; $i = 2;
        while (Establishment::withTrashed()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }

    private function optionalRequestUser(Request $request): ?User
    {
        $token = $request->bearerToken(); if (! $token) return null;
        try { $user = JWTAuth::setToken($token)->authenticate(); return $user instanceof User ? $user : null; } catch (Throwable) { return null; }
    }
}
