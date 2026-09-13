<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\EventPass;
use App\Models\ImportantEvent;
use App\Models\ImportantEventRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportantEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $perPage = min(100, max(10, (int) $request->integer('per_page', 30)));
        $query = $this->filteredQuery($request)
            ->with(['application:id,name,slug,logo'])
            ->with(['reads' => fn ($q) => $q->where('user_id', $userId)->select('id', 'important_event_id', 'user_id', 'read_at')]);

        if ($request->boolean('unread')) {
            $query->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId));
        }

        $events = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
        $events->through(function (ImportantEvent $event) {
            $read = $event->reads->first();
            $data = $event->toArray();
            unset($data['reads']);
            $data['read_at'] = $read?->read_at?->toIso8601String();
            $data['is_read'] = (bool) $read;
            return $data;
        });

        $base = $this->filteredQuery($request);
        return response()->json([
            'events' => $events,
            'summary' => [
                'total' => (clone $base)->count(),
                'unread' => (clone $base)->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))->count(),
                'critical' => (clone $base)->where('severity', 'critical')->count(),
                'warning' => (clone $base)->whereIn('severity', ['warning', 'attention'])->count(),
                'success' => (clone $base)->where('severity', 'success')->count(),
            ],
        ]);
    }

    public function show(Request $request, ImportantEvent $importantEvent): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $importantEvent->load([
            'application:id,name,slug,logo',
            'actor:id,user_name,first_name,last_name,email,phone,avatar,city,uf',
        ]);

        $read = ImportantEventRead::query()
            ->where('important_event_id', $importantEvent->id)
            ->where('user_id', $userId)
            ->first();

        $data = $importantEvent->toArray();
        $data['read_at'] = $read?->read_at?->toIso8601String();
        $data['is_read'] = (bool) $read;
        $data['details'] = $this->referenceDetails($importantEvent);

        return response()->json(['event' => $data]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        return response()->json(['unread' => ImportantEvent::query()->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))->count()]);
    }

    public function markRead(Request $request, ImportantEvent $importantEvent): JsonResponse
    {
        $read = ImportantEventRead::query()->updateOrCreate(
            ['important_event_id' => $importantEvent->id, 'user_id' => (int) $request->user()->id],
            ['read_at' => now()]
        );
        return response()->json(['success' => true, 'read_at' => $read->read_at?->toIso8601String()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $now = now();
        $updated = 0;
        ImportantEvent::query()->select('id')->orderBy('id')->chunkById(500, function ($events) use ($userId, $now, &$updated) {
            $rows = $events->map(fn ($event) => [
                'important_event_id' => (int) $event->id,
                'user_id' => $userId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if ($rows === []) return;
            DB::table('important_event_reads')->upsert($rows, ['important_event_id', 'user_id'], ['read_at', 'updated_at']);
            $updated += count($rows);
        });
        return response()->json(['success' => true, 'marked' => $updated]);
    }

    private function referenceDetails(ImportantEvent $importantEvent): array
    {
        $metadata = (array) $importantEvent->metadata;
        $fallbackBuyer = $this->userSummary($importantEvent->actor);

        $details = [
            'buyer' => $fallbackBuyer ?: [
                'id' => isset($metadata['buyer_id']) ? (int) $metadata['buyer_id'] : null,
                'name' => $metadata['buyer_name'] ?? null,
                'user_name' => null,
                'email' => null,
                'phone' => null,
                'avatar' => null,
                'city' => null,
                'uf' => null,
            ],
            'order' => null,
            'event' => null,
            'production' => null,
            'payment' => null,
            'items' => [],
            'holders' => [],
        ];

        if ($importantEvent->reference_type !== 'commerce_order' || ! ctype_digit((string) $importantEvent->reference_id)) {
            return $details;
        }

        $order = CommerceOrder::query()
            ->with([
                'application:id,name,slug,logo',
                'event:id,app_id,production_id,title,slug,image,start_date,end_date,venue,city,uf',
                'production:id,user_id,name,slug,logo',
                'user:id,user_name,first_name,last_name,email,phone,avatar,city,uf',
                'items:id,order_id,type,ticket_id,event_item_id,name,unit_price,quantity,subtotal,metadata',
                'payments:id,order_id,provider,method,status,provider_payment_id,provider_txid,amount,provider_fee,ticket_url,paid_at,refunded_at,failed_at',
            ])
            ->find((int) $importantEvent->reference_id);

        if (! $order) {
            return $details;
        }

        $payment = $order->payments->sortByDesc('id')->first();
        $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();

        $holders = $orderItemIds->isEmpty()
            ? collect()
            : EventPass::query()
                ->whereIn('commerce_order_item_id', $orderItemIds)
                ->with('user:id,user_name,first_name,last_name,email,phone,avatar')
                ->orderBy('id')
                ->get(['id','ticket_id','commerce_order_item_id','event_id','user_id','holder_name','holder_email','status','checked_in_at']);

        $details['buyer'] = $this->userSummary($order->user) ?: $details['buyer'];
        $details['order'] = [
            'id' => (int) $order->id,
            'public_id' => (string) $order->public_id,
            'status' => (string) $order->status,
            'currency' => (string) ($order->currency ?: 'BRL'),
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'platform_fee' => (float) $order->platform_fee,
            'processor_fee' => (float) $order->processor_fee,
            'total' => (float) $order->total,
            'producer_net' => (float) $order->producer_net,
            'payment_method' => $order->payment_method,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
        ];
        $details['event'] = $order->event ? [
            'id' => (int) $order->event->id,
            'title' => (string) $order->event->title,
            'slug' => (string) $order->event->slug,
            'image' => $order->event->image,
            'start_date' => $order->event->start_date?->toIso8601String(),
            'end_date' => $order->event->end_date?->toIso8601String(),
            'venue' => $order->event->venue,
            'city' => $order->event->city,
            'uf' => $order->event->uf,
        ] : null;
        $details['production'] = $order->production ? [
            'id' => (int) $order->production->id,
            'name' => (string) $order->production->name,
            'slug' => (string) $order->production->slug,
            'logo' => $order->production->logo,
        ] : null;
        $details['payment'] = $payment ? [
            'id' => (int) $payment->id,
            'provider' => $payment->provider,
            'method' => $payment->method,
            'status' => $payment->status,
            'provider_payment_id' => $payment->provider_payment_id,
            'provider_txid' => $payment->provider_txid,
            'amount' => (float) $payment->amount,
            'provider_fee' => (float) $payment->provider_fee,
            'ticket_url' => $payment->ticket_url,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
        ] : null;
        $details['items'] = $order->items->map(fn ($item) => [
            'id' => (int) $item->id,
            'type' => (string) $item->type,
            'name' => (string) $item->name,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
        ])->values()->all();
        $details['holders'] = $holders->map(fn ($pass) => [
            'id' => (int) $pass->id,
            'status' => (string) $pass->status,
            'holder_name' => $pass->holder_name ?: ($this->userSummary($pass->user)['name'] ?? null),
            'holder_email' => $pass->holder_email ?: $pass->user?->email,
            'user_id' => $pass->user_id ? (int) $pass->user_id : null,
            'checked_in_at' => $pass->checked_in_at?->toIso8601String(),
        ])->values()->all();

        return $details;
    }

    private function userSummary($user): ?array
    {
        if (! $user) {
            return null;
        }

        $name = trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null])));

        return [
            'id' => (int) $user->id,
            'name' => $name !== '' ? $name : ($user->user_name ?: $user->email),
            'user_name' => $user->user_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'city' => $user->city,
            'uf' => $user->uf,
        ];
    }

    private function filteredQuery(Request $request)
    {
        $query = ImportantEvent::query();
        if ($request->filled('app_id')) $query->where('app_id', (int) $request->integer('app_id'));
        if ($request->filled('type')) $query->where('type', (string) $request->string('type'));
        if ($request->filled('severity')) $query->where('severity', (string) $request->string('severity'));
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', '%'.$term.'%')
                    ->orWhere('message', 'like', '%'.$term.'%')
                    ->orWhere('type', 'like', '%'.$term.'%')
                    ->orWhere('reference_id', 'like', '%'.$term.'%');
            });
        }
        return $query;
    }
}
