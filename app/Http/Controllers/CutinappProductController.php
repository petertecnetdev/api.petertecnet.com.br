<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CutinappProductController extends Controller
{
    private function event(int $eventId): Event
    {
        return Event::query()->with('production')->findOrFail($eventId);
    }

    private function guard(Event $event): void
    {
        $userId = (int) Auth::id();
        $allowed = (int) optional($event->production)->user_id === $userId
            || DB::table('cutinapp_event_members')
                ->where('event_id', $event->id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->whereIn('role', ['producer','manager'])
                ->exists();
        abort_unless($allowed, 403, 'Você não pode gerenciar produtos deste evento.');
    }

    private function appId(): int
    {
        $id = Application::query()->where('slug', 'cutinapp')->value('id');
        abort_unless($id, 500, 'Aplicação Cutinapp não está cadastrada na API.');
        return (int) $id;
    }

    public function index(int $eventId)
    {
        $event = $this->event($eventId);
        $this->guard($event);
        return response()->json(Item::query()
            ->where('app_id', $this->appId())
            ->where('entity_name', 'event')
            ->where('entity_id', $eventId)
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request, int $eventId)
    {
        $event = $this->event($eventId);
        $this->guard($event);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:50000',
            'price' => 'required|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'category' => 'nullable|string|max:255',
            'is_featured' => 'sometimes|boolean',
            'status' => 'sometimes|boolean',
            'availability_start' => 'nullable|date',
            'availability_end' => 'nullable|date|after_or_equal:availability_start',
        ]);

        $item = Item::create(array_merge($data, [
            'app_id' => $this->appId(),
            'entity_name' => 'event',
            'entity_id' => $eventId,
            'type' => 'product',
            'slug' => Str::slug($data['name']) . '-' . Str::lower(Str::random(6)),
            'user_id' => Auth::id(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'status' => $data['status'] ?? true,
            'is_featured' => $data['is_featured'] ?? false,
        ]));

        return response()->json(['message' => 'Produto criado com sucesso.', 'item' => $item], 201);
    }

    public function update(Request $request, int $eventId, int $itemId)
    {
        $event = $this->event($eventId);
        $this->guard($event);
        $item = Item::query()->where('app_id', $this->appId())->where('entity_name','event')->where('entity_id',$eventId)->findOrFail($itemId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:50000',
            'price' => 'sometimes|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'category' => 'nullable|string|max:255',
            'is_featured' => 'sometimes|boolean',
            'status' => 'sometimes|boolean',
            'availability_start' => 'nullable|date',
            'availability_end' => 'nullable|date|after_or_equal:availability_start',
        ]);
        $item->fill($data);
        $item->updated_by = Auth::id();
        if (isset($data['name'])) $item->slug = Str::slug($data['name']) . '-' . Str::lower(Str::random(6));
        $item->save();
        return response()->json(['message' => 'Produto atualizado.', 'item' => $item]);
    }

    public function destroy(int $eventId, int $itemId)
    {
        $event = $this->event($eventId);
        $this->guard($event);
        $item = Item::query()->where('app_id', $this->appId())->where('entity_name','event')->where('entity_id',$eventId)->findOrFail($itemId);
        $item->delete();
        return response()->json(['message' => 'Produto excluído.']);
    }
}
