<?php

namespace App\Domain\Commerce\Services;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\Item;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EventCatalogService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function catalog(User $actor, int $eventId): array
    {
        $event = $this->ownedEvent($actor, $eventId);
        $sources = $this->sourceItems($event);
        $linked = EventItem::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->get()
            ->keyBy(fn (EventItem $item) => $item->source_item_id ? (int) $item->source_item_id : 'manual-'.$item->id);

        $catalog = $sources->map(function (Item $source) use ($linked, $event) {
            $source->setAppends(['image_url']);
            $eventItem = $linked->get((int) $source->id);

            return [
                'item_id' => (int) $source->id,
                'name' => $source->name,
                'description' => $source->description,
                'sku' => $source->sku,
                'category' => $source->category,
                'brand' => $source->brand,
                'image_url' => $source->image_url,
                'catalog_price' => (float) $source->price,
                'catalog_stock' => $source->stock === null ? null : (int) $source->stock,
                'selected' => (bool) ($eventItem?->is_active),
                'event_item' => $eventItem ? $this->eventItemPayload($eventItem) : null,
                'defaults' => [
                    'price' => (float) $source->price,
                    'quantity' => $this->defaultQuantity($source, $event),
                ],
            ];
        })->values();

        $manual = $linked
            ->filter(fn (EventItem $item) => ! $item->source_item_id)
            ->map(fn (EventItem $item) => $this->eventItemPayload($item))
            ->values();

        return [
            'event' => $event->only(['id', 'title', 'production_id', 'event_series_id', 'start_date', 'end_date']),
            'catalog' => $catalog,
            'manual_items' => $manual,
            'selected_count' => $catalog->where('selected', true)->count(),
        ];
    }

    public function sync(User $actor, int $eventId, array $input): array
    {
        $event = $this->ownedEvent($actor, $eventId);
        $sources = $this->sourceItems($event)->keyBy('id');
        $replace = (bool) ($input['replace'] ?? true);
        $allActive = (bool) ($input['all_active'] ?? false);

        $requested = collect($input['items'] ?? []);
        if ($allActive) {
            $requested = $sources->values()->map(fn (Item $source) => [
                'item_id' => (int) $source->id,
                'price' => (float) $source->price,
                'quantity' => $this->defaultQuantity($source, $event),
                'is_active' => true,
            ]);
        }

        $normalized = $requested
            ->map(function (array $row) use ($sources, $event) {
                $sourceId = (int) ($row['item_id'] ?? 0);
                /** @var Item|null $source */
                $source = $sources->get($sourceId);
                abort_unless($source, 422, 'Um dos itens selecionados não pertence ao catálogo deste estabelecimento.');

                $price = array_key_exists('price', $row) && $row['price'] !== null
                    ? round((float) $row['price'], 2)
                    : round((float) $source->price, 2);
                $quantity = array_key_exists('quantity', $row) && $row['quantity'] !== null
                    ? (int) $row['quantity']
                    : $this->defaultQuantity($source, $event);

                abort_if($price <= 0, 422, 'Itens para pré-compra precisam ter preço maior que zero.');
                abort_if($quantity < 0 || $quantity > 1000000, 422, 'A quantidade disponível deve ficar entre 0 e 1.000.000.');

                return [
                    'source' => $source,
                    'price' => $price,
                    'quantity' => $quantity,
                    'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                ];
            })
            ->keyBy(fn (array $row) => (int) $row['source']->id);

        DB::transaction(function () use ($event, $normalized, $replace): void {
            foreach ($normalized as $sourceId => $row) {
                /** @var Item $source */
                $source = $row['source'];
                EventItem::query()->updateOrCreate(
                    [
                        'app_id' => $this->context->id(),
                        'event_id' => $event->id,
                        'source_item_id' => $sourceId,
                    ],
                    [
                        'name' => $source->name,
                        'description' => $source->description,
                        'price' => $row['price'],
                        'quantity' => $row['quantity'],
                        'is_active' => $row['is_active'],
                    ]
                );
            }

            if ($replace) {
                EventItem::query()
                    ->where('app_id', $this->context->id())
                    ->where('event_id', $event->id)
                    ->whereNotNull('source_item_id')
                    ->when(
                        $normalized->isNotEmpty(),
                        fn ($query) => $query->whereNotIn('source_item_id', $normalized->keys()->all())
                    )
                    ->update(['is_active' => false]);
            }
        });

        return [
            'message' => 'Produtos para pré-compra atualizados para esta data do evento.',
            ...$this->catalog($actor, $eventId),
        ];
    }

    public function copyLinkedItems(Event $source, Event $target): int
    {
        $items = EventItem::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $source->id)
            ->get();

        foreach ($items as $item) {
            EventItem::create([
                'app_id' => $this->context->id(),
                'event_id' => $target->id,
                'source_item_id' => $item->source_item_id,
                'name' => $item->name,
                'description' => $item->description,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'is_active' => $item->is_active,
            ]);
        }

        return $items->count();
    }

    private function ownedEvent(User $actor, int $eventId): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production:id,app_id,user_id,name')
            ->findOrFail($eventId);

        abort_unless(
            $event->production && (int) $event->production->app_id === $this->context->id(),
            404,
            'Evento não encontrado neste contexto.'
        );
        abort_unless(
            $actor->hasProfile('Administrador') || (int) $event->production->user_id === (int) $actor->id,
            403,
            'Você não pode gerenciar os produtos deste evento.'
        );

        return $event;
    }

    private function sourceItems(Event $event): Collection
    {
        return Item::query()
            ->with('files')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $event->production_id)
            ->where('status', true)
            ->where('price', '>', 0)
            ->orderByDesc('is_featured')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    private function defaultQuantity(Item $source, Event $event): int
    {
        if ($source->stock !== null) {
            return max(0, min(1000000, (int) $source->stock));
        }

        return max(1, min(1000000, (int) ($event->max_attendees ?: 1000)));
    }

    private function eventItemPayload(EventItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'source_item_id' => $item->source_item_id ? (int) $item->source_item_id : null,
            'name' => $item->name,
            'description' => $item->description,
            'price' => (float) $item->price,
            'quantity' => (int) $item->quantity,
            'is_active' => (bool) $item->is_active,
        ];
    }
}
