<?php

namespace App\Observers;

use App\Models\Item;
use App\Models\Order;

class OrderPricingObserver
{
    public function updating(Order $order): void
    {
        if (! $order->isDirty('total_price')) {
            return;
        }

        $order->loadMissing('items.modifiers');

        $modifierIds = $order->items
            ->flatMap(fn ($orderItem) => $orderItem->modifiers
                ->where('type', 'addition')
                ->pluck('modifier_id'))
            ->filter()
            ->unique()
            ->values();

        $modifierPrices = Item::query()
            ->whereIn('id', $modifierIds)
            ->pluck('price', 'id');

        $total = 0.0;

        foreach ($order->items as $orderItem) {
            $total += (float) ($orderItem->subtotal ?? 0);

            foreach ($orderItem->modifiers->where('type', 'addition') as $modifier) {
                $quantity = max(1, (int) ($modifier->quantity ?? 1));
                $total += (float) ($modifierPrices[$modifier->modifier_id] ?? 0) * $quantity;
            }
        }

        $order->total_price = round($total, 2);
    }
}
