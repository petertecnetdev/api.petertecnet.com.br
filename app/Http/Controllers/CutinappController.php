<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CutinappController extends Controller
{
    private const APP = 'cutinapp';

    public function config()
    {
        return response()->json([
            'google_client_id' => (string) config('services.google.client_id'),
            'app' => self::APP,
        ]);
    }

    public function publicEvents(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = Event::query()
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->with('production:id,name,slug,user_id,app_slug')
            ->orderBy('start_date');

        if (! empty($data['city'])) {
            $query->where('city', $data['city']);
        }

        return response()->json(['events' => $query->paginate($data['per_page'] ?? 24)]);
    }

    public function publicEvent(string $slug)
    {
        $event = Event::query()
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->with([
                'production:id,name,slug,user_id,app_slug',
                'tickets' => fn ($query) => $query
                    ->where('app_slug', self::APP)
                    ->where('price', 0)
                    ->orderBy('created_at'),
            ])
            ->firstOrFail();

        return response()->json([
            'event' => $event,
            'tickets' => $event->tickets,
        ]);
    }

    public function myProductions()
    {
        return response()->json([
            'productions' => Production::query()
                ->where('app_slug', self::APP)
                ->where('user_id', Auth::id())
                ->withCount(['events' => fn ($query) => $query->where('app_slug', self::APP)])
                ->latest()
                ->get(),
        ]);
    }

    public function showProduction(int $id)
    {
        $production = $this->ownedProduction($id);
        return response()->json(['production' => $production]);
    }

    public function createProduction(Request $request)
    {
        $data = $request->validate($this->productionRules(true));
        $data['user_id'] = Auth::id();
        $data['app_slug'] = self::APP;
        $data['slug'] = $this->uniqueProductionSlug($data['name']);
        $data['is_published'] = true;
        $data['is_cancelled'] = false;
        unset($data['logo'], $data['background']);

        $production = Production::create($data);
        $this->storeProductionImages($request, $production);

        return response()->json([
            'message' => 'Produção criada. Agora você já pode cadastrar seu primeiro evento.',
            'production' => $production->fresh(),
        ], 201);
    }

    public function updateProduction(Request $request, int $id)
    {
        $production = $this->ownedProduction($id);
        $data = $request->validate($this->productionRules(false));

        if (! empty($data['name']) && $data['name'] !== $production->name) {
            $data['slug'] = $this->uniqueProductionSlug($data['name'], $production->id);
        }

        unset($data['logo'], $data['background'], $data['user_id'], $data['app_slug']);
        $production->update($data);
        $this->storeProductionImages($request, $production);

        return response()->json(['message' => 'Produção atualizada.', 'production' => $production->fresh()]);
    }

    public function myEvents(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));
        return response()->json([
            'events' => Event::query()
                ->where('app_slug', self::APP)
                ->whereHas('production', fn ($query) => $query->where('user_id', Auth::id())->where('app_slug', self::APP))
                ->with('production:id,name,slug,user_id,app_slug')
                ->orderByDesc('start_date')
                ->paginate($perPage),
        ]);
    }

    public function showEvent(int $id)
    {
        $event = $this->ownedEvent($id);
        return response()->json(['event' => $event->load('production:id,name,slug,user_id,app_slug')]);
    }

    public function createEvent(Request $request)
    {
        $data = $request->validate($this->eventRules(true));
        $production = $this->ownedProduction((int) $data['production_id']);

        $data['app_slug'] = self::APP;
        $data['slug'] = $this->uniqueEventSlug($data['title']);
        $data['is_published'] = array_key_exists('is_published', $data) ? (bool) $data['is_published'] : true;
        $data['is_cancelled'] = (bool) ($data['is_cancelled'] ?? false);
        unset($data['image']);

        $event = Event::create($data);
        if ($request->hasFile('image')) {
            $event->image = $this->storeEventImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento criado com sucesso.',
            'event' => $event->fresh()->load('production:id,name,slug,user_id,app_slug'),
        ], 201);
    }

    public function updateEvent(Request $request, int $id)
    {
        $event = $this->ownedEvent($id);
        $data = $request->validate($this->eventRules(false));

        if (isset($data['production_id'])) {
            $this->ownedProduction((int) $data['production_id']);
        }
        if (! empty($data['title']) && $data['title'] !== $event->title) {
            $data['slug'] = $this->uniqueEventSlug($data['title'], $event->id);
        }
        unset($data['image'], $data['app_slug']);
        $event->update($data);

        if ($request->hasFile('image')) {
            if ($event->image && str_starts_with($event->image, 'images/cutinapp/events/')) {
                Storage::disk('public')->delete($event->image);
            }
            $event->image = $this->storeEventImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento atualizado com sucesso.',
            'event' => $event->fresh()->load('production:id,name,slug,user_id,app_slug'),
        ]);
    }

    public function createCourtesy(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ]);

        $event = $this->ownedEvent((int) $data['event_id']);
        $ticket = Ticket::create([
            'app_slug' => self::APP,
            'event_id' => $event->id,
            'name' => $data['name'],
            'ticket_type' => 'courtesy',
            'type' => 'courtesy',
            'price' => 0,
            'quantity' => $data['quantity'],
            'limit_date' => $data['limit_date'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json(['message' => 'Cortesia criada com sucesso.', 'ticket' => $ticket], 201);
    }

    private function ownedProduction(int $id): Production
    {
        $production = Production::query()->where('app_slug', self::APP)->findOrFail($id);
        abort_unless(Auth::user()?->hasProfile('Administrador') || (int) $production->user_id === (int) Auth::id(), 403, 'Você não pode gerenciar esta produção.');
        return $production;
    }

    private function ownedEvent(int $id): Event
    {
        $event = Event::query()->where('app_slug', self::APP)->with('production')->findOrFail($id);
        abort_unless(Auth::user()?->hasProfile('Administrador') || (int) optional($event->production)->user_id === (int) Auth::id(), 403, 'Você não pode gerenciar este evento.');
        return $event;
    }

    private function productionRules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return [
            'name' => $required . 'string|max:255', 'fantasy' => 'sometimes|nullable|string|max:255',
            'cnpj' => 'sometimes|nullable|string|max:18', 'phone' => 'sometimes|nullable|string|max:30',
            'description' => 'sometimes|nullable|string|max:10000', 'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|max:2', 'address' => 'sometimes|nullable|string|max:255',
            'website_url' => 'sometimes|nullable|url|max:2048', 'instagram_url' => 'sometimes|nullable|url|max:2048',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function eventRules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return [
            'production_id' => $required . 'integer|exists:productions,id', 'title' => $required . 'string|max:255',
            'description' => $required . 'string|max:50000', 'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'address' => $required . 'string|max:500', 'start_date' => $required . 'date', 'end_date' => $required . 'date|after_or_equal:start_date',
            'venue' => 'sometimes|nullable|string|max:255', 'uf' => 'sometimes|nullable|string|max:2', 'city' => 'sometimes|nullable|string|max:120',
            'cep' => 'sometimes|nullable|string|max:20', 'max_attendees' => 'sometimes|nullable|integer|min:0',
            'contact_email' => 'sometimes|nullable|email|max:255', 'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_published' => 'sometimes|boolean', 'is_cancelled' => 'sometimes|boolean', 'is_private' => 'sometimes|boolean',
        ];
    }

    private function storeProductionImages(Request $request, Production $production): void
    {
        foreach (['logo' => [600, 600], 'background' => [1920, 700]] as $field => $size) {
            if (! $request->hasFile($field)) continue;
            if ($production->{$field} && str_starts_with($production->{$field}, 'images/cutinapp/productions/')) Storage::disk('public')->delete($production->{$field});
            $path = 'images/cutinapp/productions/' . $field . '-' . Str::uuid() . '.webp';
            $absolute = Storage::disk('public')->path($path);
            if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0755, true);
            Image::make($request->file($field)->getRealPath())->orientate()->fit($size[0], $size[1])->encode('webp', 86)->save($absolute);
            $production->{$field} = $path;
        }
        $production->save();
    }

    private function storeEventImage($file): string
    {
        $path = 'images/cutinapp/events/' . Str::uuid() . '.webp';
        $absolute = Storage::disk('public')->path($path);
        if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0755, true);
        Image::make($file->getRealPath())->orientate()->fit(1400, 788)->encode('webp', 86)->save($absolute);
        return $path;
    }

    private function uniqueProductionSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'producao-' . Str::lower(Str::random(8)); $slug = $base; $i = 2;
        while (Production::query()->where('app_slug', self::APP)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }

    private function uniqueEventSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'evento-' . Str::lower(Str::random(8)); $slug = $base; $i = 2;
        while (Event::query()->where('app_slug', self::APP)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }
}
