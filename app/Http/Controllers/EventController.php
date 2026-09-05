<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Interaction;
use App\Models\Production;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;

class EventController extends Controller
{
    public function list(Request $request)
    {
        $data = $request->validate([
            'production_id' => 'nullable|integer|exists:productions,id',
            'city' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Event::query()->with('production:id,name,slug,user_id');

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
        $data = $this->prepareOpeningMedia($request, $data, null, true);

        $event = Event::create($data);

        return response()->json([
            'message' => 'Evento cadastrado com sucesso.',
            'event' => $event->load('production:id,name,slug,user_id'),
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

        $oldOpeningVideo = $event->opening_media_type === 'video' ? $event->opening_media : null;
        $data = $this->prepareOpeningMedia($request, $data, $event, false);

        $event->update($data);
        $event = $event->fresh()->load('production:id,name,slug,user_id');

        if ($oldOpeningVideo && ($event->opening_media_type !== 'video' || $event->opening_media !== $oldOpeningVideo)) {
            $this->deleteOpeningVideo($oldOpeningVideo);
        }

        return response()->json([
            'message' => 'Evento atualizado com sucesso.',
            'event' => $event,
        ]);
    }

    public function show($id)
    {
        $event = Event::with(['production:id,name,slug,user_id', 'tickets'])->findOrFail($id);
        return response()->json(['event' => $event]);
    }

    public function view($slug)
    {
        $event = Event::where('slug', $slug)->with(['production:id,name,slug,user_id', 'tickets'])->firstOrFail();
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
        $openingVideo = $event->opening_media_type === 'video' ? $event->opening_media : null;
        $event->delete();
        $this->deleteImage($image);
        $this->deleteOpeningVideo($openingVideo);

        return response()->json(['message' => 'Evento excluído com sucesso.']);
    }

    public function myEvents(Request $request)
    {
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);

        $events = Event::query()
            ->whereHas('production', fn ($q) => $q->where('user_id', Auth::id()))
            ->with('production:id,name,slug,user_id')
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
            'opening_media_type' => 'sometimes|nullable|in:banner,video,youtube',
            'opening_media_url' => 'sometimes|nullable|url|max:2000',
            'opening_media_file' => 'sometimes|nullable|file|mimetypes:video/mp4,video/webm,video/quicktime|max:51200',
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

    private function prepareOpeningMedia(Request $request, array $data, ?Event $event, bool $creating): array
    {
        $hasType = array_key_exists('opening_media_type', $data);
        $type = $hasType
            ? ($data['opening_media_type'] ?: 'banner')
            : ($creating ? 'banner' : ($event?->opening_media_type ?: 'banner'));
        $url = trim((string) ($data['opening_media_url'] ?? ''));

        unset($data['opening_media_url'], $data['opening_media_file']);

        if (! $creating && ! $hasType && ! $request->hasFile('opening_media_file') && ! $request->exists('opening_media_url')) {
            return $data;
        }

        if ($type === 'video') {
            if ($request->hasFile('opening_media_file')) {
                $data['opening_media'] = $this->storeOpeningVideo($request->file('opening_media_file'));
            } elseif (! $event || $event->opening_media_type !== 'video' || ! $event->opening_media) {
                throw ValidationException::withMessages([
                    'opening_media_file' => ['Envie o vídeo que será exibido na abertura do evento.'],
                ]);
            } else {
                $data['opening_media'] = $event->opening_media;
            }
        } elseif ($type === 'youtube') {
            if (! $this->youtubeVideoId($url)) {
                throw ValidationException::withMessages([
                    'opening_media_url' => ['Informe um link válido de vídeo do YouTube.'],
                ]);
            }
            $data['opening_media'] = $url;
        } else {
            $type = 'banner';
            $data['opening_media'] = null;
        }

        $data['opening_media_type'] = $type;

        return $data;
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

    private function storeOpeningVideo($uploaded): string
    {
        $extension = strtolower((string) ($uploaded->getClientOriginalExtension() ?: $uploaded->extension() ?: 'mp4'));
        if (! in_array($extension, ['mp4', 'webm', 'mov'], true)) {
            $extension = 'mp4';
        }

        return $uploaded->storeAs('videos/events', Str::uuid() . '.' . $extension, 'public');
    }

    private function youtubeVideoId(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $path = (string) ($parts['path'] ?? '');
        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = trim(explode('/', trim($path, '/'))[0] ?? '');
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (! empty($query['v'])) {
                $candidate = (string) $query['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $matches)) {
                $candidate = $matches[1];
            }
        }

        return $candidate && preg_match('/^[A-Za-z0-9_-]{6,}$/', $candidate) ? $candidate : null;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'images/events/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function deleteOpeningVideo(?string $path): void
    {
        if ($path && str_starts_with($path, 'videos/events/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
