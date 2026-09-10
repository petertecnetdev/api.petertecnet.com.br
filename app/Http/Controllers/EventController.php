<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\Item;
use App\Models\Interaction;
use App\Models\Production;
use App\Services\EventProducerCommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class EventController extends Controller
{
    private const PRODUCTION_RELATION = 'production:id,name,slug,user_id,phone,contact_phone';

    public function list(Request $request)
    {
        $data = $request->validate([
            'production_id' => 'nullable|integer|exists:productions,id',
            'city' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Event::query()->with(self::PRODUCTION_RELATION);

        if (isset($data['production_id'])) {
            $query->where('production_id', $data['production_id']);
        }
        if (! empty($data['city'])) {
            $query->where('city', $data['city']);
        }

        return response()->json([
            'events' => $query->orderByDesc('start_date')->paginate($data['per_page'] ?? 25),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateEvent($request, true);
        $production = Production::findOrFail($data['production_id']);

        if (! $this->canManageProduction($production, 'event_create')) {
            return response()->json(['error' => 'Você não tem permissão para criar eventos nesta produção.'], 403);
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
        $data['image'] = $request->hasFile('image') ? $this->storeImage($request->file('image')) : null;

        $event = Event::create($data);
        $importedItems = $request->boolean('use_production_items')
            ? $this->attachProductionItems($event)
            : 0;

        try {
            app(EventProducerCommunicationService::class)->notify($event, 'created');
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Evento cadastrado com sucesso.',
            'event' => $event->load(self::PRODUCTION_RELATION),
            'event_items_imported' => $importedItems,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $event = Event::with('production')->findOrFail($id);

        if (! $this->canManageProduction($event->production, 'event_edit')) {
            return response()->json(['error' => 'Você não tem permissão para atualizar este evento.'], 403);
        }

        $data = $this->validateEvent($request, false);

        if (isset($data['production_id']) && (int) $data['production_id'] !== (int) $event->production_id) {
            $target = Production::findOrFail($data['production_id']);
            if (! $this->canManageProduction($target, 'event_create')) {
                return response()->json(['error' => 'Você não tem permissão para mover o evento para esta produção.'], 403);
            }
        }

        if (isset($data['title']) || array_key_exists('slug', $data)) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title'] ?? $event->title, $event->id);
        }

        if ($request->hasFile('image')) {
            $oldImage = $event->image;
            $data['image'] = $this->storeImage($request->file('image'));
            $this->deleteImage($oldImage);
        }

        $event->update($data);

        $changedFields = collect(array_keys($event->getChanges()))
            ->reject(fn ($field) => in_array($field, ['created_at', 'updated_at'], true))
            ->values()
            ->all();

        if ($changedFields !== []) {
            $action = 'updated';

            if (in_array('is_cancelled', $changedFields, true)) {
                $action = $event->is_cancelled ? 'deactivated' : 'reactivated';
            } elseif (in_array('is_published', $changedFields, true)) {
                $action = $event->is_published ? 'activated' : 'deactivated';
            }

            try {
                $event->refresh();
                app(EventProducerCommunicationService::class)->notify($event, $action, $changedFields);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Evento atualizado com sucesso.',
            'event' => $event->fresh()->load(self::PRODUCTION_RELATION),
        ]);
    }

    public function show($id)
    {
        $event = Event::with([self::PRODUCTION_RELATION, 'tickets'])->findOrFail($id);
        $this->normalizeProductionPhone($event);
        return response()->json(['event' => $event]);
    }

    public function view($slug)
    {
        $event = Event::where('slug', $slug)->with([self::PRODUCTION_RELATION, 'tickets'])->firstOrFail();
        $this->normalizeProductionPhone($event);
        $user = Auth::user();

        if ($event->is_private && ! $this->canManageProduction($event->production, 'event_edit')) {
            return response()->json(['error' => 'Evento privado.'], 403);
        }

        if ($user) {
            Interaction::registerView($event, $user);
        }

        $liked = $user ? Interaction::where([
            'user_id' => $user->id,
            'entity_id' => $event->id,
            'entity_type' => 'Event',
            'interaction_type' => 'like',
        ])->exists() : false;

        $confirmed = $user ? Interaction::where([
            'user_id' => $user->id,
            'entity_id' => $event->id,
            'entity_type' => 'Event',
            'interaction_type' => 'confirm',
        ])->exists() : false;

        $events = Event::query()
            ->where('production_id', $event->production_id)
            ->where('id', '!=', $event->id)
            ->where('is_cancelled', false)
            ->orderBy('start_date')
            ->limit(20)
            ->get();

        return response()->json([
            'event' => $event,
            'events' => $events,
            'tickets' => $event->tickets,
            'liked' => $liked,
            'confirmed' => $confirmed,
        ]);
    }

    public function delete($id)
    {
        $event = Event::with('production')->findOrFail($id);

        if (! $this->canManageProduction($event->production, 'event_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir este evento.'], 403);
        }

        $image = $event->image;
        $event->delete();
        $this->deleteImage($image);

        return response()->json(['message' => 'Evento excluído com sucesso.']);
    }

    public function myEvents(Request $request)
    {
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);

        $events = Event::query()
            ->whereHas('production', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(self::PRODUCTION_RELATION)
            ->orderByDesc('start_date')
            ->paginate($data['per_page'] ?? 25);

        return response()->json(['events' => $events]);
    }

    private function validateEvent(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'production_id' => "$required|integer|exists:productions,id",
            'title' => "$required|string|max:255",
            'description' => "$required|string|max:50000",
            'image' => ($creating ? 'nullable' : 'sometimes|nullable') . '|image|mimes:jpeg,png,jpg,webp|max:5120',
            'address' => "$required|string|max:500",
            'start_date' => "$required|date",
            'end_date' => "$required|date|after_or_equal:start_date",
            'venue' => 'sometimes|nullable|string|max:255',
            'uf' => 'sometimes|nullable|string|max:2',
            'establishment_type' => 'sometimes|nullable|string|max:100',
            'slug' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:120',
            'state' => 'sometimes|nullable|string|max:120',
            'country' => 'sometimes|nullable|string|max:120',
            'location' => 'sometimes|nullable|string|max:500',
            'cep' => 'sometimes|nullable|string|max:20',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'is_featured' => 'sometimes|boolean',
            'is_published' => 'sometimes|boolean',
            'is_approved' => 'sometimes|boolean',
            'is_cancelled' => 'sometimes|boolean',
            'max_attendees' => 'sometimes|nullable|integer|min:0',
            'remaining_tickets' => 'sometimes|nullable|integer|min:0',
            'extra_info' => 'sometimes|nullable|array',
            'agenda' => 'sometimes|nullable|array',
            'menu' => 'sometimes|nullable|array',
            'additional_info' => 'sometimes|nullable|array',
            'facebook_url' => 'sometimes|nullable|url|max:500',
            'twitter_url' => 'sometimes|nullable|url|max:500',
            'instagram_url' => 'sometimes|nullable|url|max:500',
            'youtube_url' => 'sometimes|nullable|url|max:500',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'website' => 'sometimes|nullable|url|max:500',
            'registration_link' => 'sometimes|nullable|url|max:500',
            'organizer_name' => 'sometimes|nullable|string|max:255',
            'organizer_email' => 'sometimes|nullable|email|max:255',
            'organizer_phone' => 'sometimes|nullable|string|max:50',
            'organizer_description' => 'sometimes|nullable|string|max:5000',
            'speaker_list' => 'sometimes|nullable|array',
            'sponsor_list' => 'sometimes|nullable|array',
            'partners' => 'sometimes|nullable|array',
            'reviews' => 'sometimes|nullable|array',
            'rating' => 'sometimes|nullable|numeric|min:0|max:5',
            'is_private' => 'sometimes|boolean',
            'requires_approval' => 'sometimes|boolean',
            'approval_message' => 'sometimes|nullable|string|max:5000',
            'segments' => 'sometimes|nullable|array',
            'establishment_name' => 'sometimes|nullable|string|max:255',
        ]);
    }

    private function attachProductionItems(Event $event): int
    {
        $items = Item::query()
            ->forApplication((int) $event->app_id)
            ->active()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $event->production_id)
            ->where('price', '>', 0)
            ->where('stock', '>', 0)
            ->get(['id', 'name', 'description', 'price', 'stock']);

        foreach ($items as $item) {
            EventItem::query()->updateOrCreate(
                [
                    'app_id' => (int) $event->app_id,
                    'event_id' => (int) $event->id,
                    'source_item_id' => (int) $item->id,
                ],
                [
                    'name' => $item->name,
                    'description' => $item->description,
                    'price' => $item->price,
                    'quantity' => max(0, (int) $item->stock),
                    'promotion_enabled' => false,
                    'promotion_price' => null,
                    'is_active' => true,
                ],
            );
        }

        return $items->count();
    }

    private function canManageProduction(?Production $production, string $permission): bool
    {
        $user = Auth::user();
        return $user && $production && (
            $user->hasProfile('Administrador')
            || (int) $production->user_id === (int) $user->id
            || $user->hasPermission($permission)
        );
    }

    private function normalizeProductionPhone(Event $event): void
    {
        if ($event->production && ! $event->production->phone && $event->production->contact_phone) {
            $event->production->phone = $event->production->contact_phone;
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::random(12);
        $slug = $base;
        $suffix = 2;

        while (Event::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private function storeImage($uploaded): string
    {
        $path = 'images/events/' . Str::uuid() . '.webp';
        $absolute = Storage::disk('public')->path($path);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        Image::make($uploaded->getRealPath())->orientate()->fit(850, 450)->encode('webp', 85)->save($absolute);
        return $path;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'images/events/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
