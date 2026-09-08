<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EventSoundtrackController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(string $slug)
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        if ($event->is_private) {
            abort(404);
        }

        return response()->json([
            'event_id' => $event->id,
            'soundtrack' => $this->normalizeSoundtrack($event),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $event = $this->ownedEvent($request, $id);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'autoplay' => 'required|boolean',
            'shuffle' => 'required|boolean',
            'loop' => 'required|boolean',
            'volume' => 'required|numeric|min:0|max:1',
            'items' => 'present|array|max:100',
            'items.*.id' => 'nullable|string|max:80',
            'items.*.type' => 'required|in:youtube,youtube_playlist,spotify,soundcloud,audio,url',
            'items.*.title' => 'nullable|string|max:255',
            'items.*.url' => 'required|url:http,https|max:4096',
            'items.*.source' => 'nullable|in:external,upload',
            'items.*.path' => 'nullable|string|max:1024',
        ]);

        $items = array_values(array_map(function (array $item) {
            return [
                'id' => $item['id'] ?? (string) Str::uuid(),
                'type' => $item['type'],
                'title' => trim((string) ($item['title'] ?? '')),
                'url' => trim((string) $item['url']),
                'source' => $item['source'] ?? 'external',
                'path' => $item['path'] ?? null,
            ];
        }, $data['items']));

        $additional = is_array($event->additional_info) ? $event->additional_info : [];
        $additional['soundtrack'] = [
            'enabled' => (bool) $data['enabled'],
            'autoplay' => (bool) $data['autoplay'],
            'shuffle' => (bool) $data['shuffle'],
            'loop' => (bool) $data['loop'],
            'volume' => round((float) $data['volume'], 2),
            'items' => $items,
            'updated_at' => now()->toIso8601String(),
        ];

        $event->forceFill(['additional_info' => $additional])->save();

        return response()->json([
            'message' => 'Trilha sonora atualizada com sucesso.',
            'soundtrack' => $additional['soundtrack'],
        ]);
    }

    public function upload(Request $request, int $id)
    {
        $event = $this->ownedEvent($request, $id);
        $data = $request->validate([
            'file' => 'required|file|max:51200|mimes:mp3,m4a,aac,ogg,oga,wav,webm,opus',
            'title' => 'nullable|string|max:255',
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;
        $path = $uploaded->storeAs('uploads/event-audio/'.$event->id, $filename, 'public');
        $url = Storage::disk('public')->url($path);

        $soundtrack = $this->normalizeSoundtrack($event);
        $item = [
            'id' => (string) Str::uuid(),
            'type' => 'audio',
            'title' => trim((string) ($data['title'] ?? pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME))),
            'url' => $url,
            'source' => 'upload',
            'path' => $path,
        ];
        $soundtrack['items'][] = $item;
        $soundtrack['enabled'] = true;
        $soundtrack['updated_at'] = now()->toIso8601String();

        $additional = is_array($event->additional_info) ? $event->additional_info : [];
        $additional['soundtrack'] = $soundtrack;
        $event->forceFill(['additional_info' => $additional])->save();

        return response()->json([
            'message' => 'Áudio enviado e adicionado à trilha sonora.',
            'item' => $item,
            'soundtrack' => $soundtrack,
        ], 201);
    }

    public function destroyItem(Request $request, int $id, string $itemId)
    {
        $event = $this->ownedEvent($request, $id);
        $soundtrack = $this->normalizeSoundtrack($event);
        $removed = null;
        $soundtrack['items'] = array_values(array_filter($soundtrack['items'], function (array $item) use ($itemId, &$removed) {
            if (($item['id'] ?? null) === $itemId) {
                $removed = $item;
                return false;
            }
            return true;
        }));

        abort_unless($removed, 404, 'Música não encontrada na trilha sonora.');

        if (($removed['source'] ?? null) === 'upload' && ! empty($removed['path'])) {
            Storage::disk('public')->delete($removed['path']);
        }

        $soundtrack['updated_at'] = now()->toIso8601String();
        $additional = is_array($event->additional_info) ? $event->additional_info : [];
        $additional['soundtrack'] = $soundtrack;
        $event->forceFill(['additional_info' => $additional])->save();

        return response()->json([
            'message' => 'Música removida da trilha sonora.',
            'soundtrack' => $soundtrack,
        ]);
    }

    private function ownedEvent(Request $request, int $id): Event
    {
        $user = $request->user();
        abort_unless($user, 401);

        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production')
            ->findOrFail($id);

        $isAdmin = $user->hasProfile('Administrador')
            || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
        $isOwner = $event->production
            && (int) $event->production->app_id === $this->context->id()
            && (int) $event->production->user_id === (int) $user->id;

        abort_unless($isAdmin || $isOwner, 403, 'Você não pode gerenciar a trilha sonora deste evento.');

        return $event;
    }

    private function normalizeSoundtrack(Event $event): array
    {
        $additional = is_array($event->additional_info) ? $event->additional_info : [];
        $soundtrack = is_array($additional['soundtrack'] ?? null) ? $additional['soundtrack'] : [];

        return [
            'enabled' => (bool) ($soundtrack['enabled'] ?? false),
            'autoplay' => (bool) ($soundtrack['autoplay'] ?? true),
            'shuffle' => (bool) ($soundtrack['shuffle'] ?? false),
            'loop' => (bool) ($soundtrack['loop'] ?? true),
            'volume' => max(0, min(1, (float) ($soundtrack['volume'] ?? 0.35))),
            'items' => array_values(array_filter((array) ($soundtrack['items'] ?? []), fn ($item) => is_array($item) && ! empty($item['url']))),
            'updated_at' => $soundtrack['updated_at'] ?? null,
        ];
    }
}
