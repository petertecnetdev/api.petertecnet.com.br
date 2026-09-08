<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\CommerceCouponService;
use App\Http\Controllers\Controller;
use App\Models\CommerceCoupon;
use App\Models\Event;
use App\Models\EventItem;
use App\Models\Production;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CommerceCouponController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceCouponService $coupons,
    ) {}

    public function index(Request $request, int $organizationId)
    {
        $this->ownedProduction($request, $organizationId);
        return response()->json(['coupons' => CommerceCoupon::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->with('event:id,title')
            ->latest()->get()]);
    }

    public function store(Request $request, int $organizationId)
    {
        $this->ownedProduction($request, $organizationId);
        $data = $this->validated($request, $organizationId);
        $data['app_id'] = $this->context->id();
        $data['production_id'] = $organizationId;
        $data['created_by'] = $request->user()->id;
        $data['code'] = $this->uniqueCode($request->input('code'));
        $coupon = CommerceCoupon::create($data);
        return response()->json(['coupon' => $coupon->load('event:id,title')], 201);
    }

    public function update(Request $request, int $organizationId, int $couponId)
    {
        $this->ownedProduction($request, $organizationId);
        $coupon = $this->ownedCoupon($organizationId, $couponId);
        $data = $this->validated($request, $organizationId, true);
        if ($request->filled('code')) $data['code'] = $this->uniqueCode($request->input('code'), $coupon->id);
        $coupon->update($data);
        return response()->json(['coupon' => $coupon->fresh()->load('event:id,title')]);
    }

    public function destroy(Request $request, int $organizationId, int $couponId)
    {
        $this->ownedProduction($request, $organizationId);
        $coupon = $this->ownedCoupon($organizationId, $couponId);
        $coupon->update(['is_active' => false]);
        return response()->json(['message' => 'Cupom desativado.']);
    }

    public function validateCode(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'coupon_code' => 'required|string|max:40',
            'tickets' => 'nullable|array|max:20', 'tickets.*.id' => 'integer', 'tickets.*.quantity' => 'integer|min:1|max:20',
            'items' => 'nullable|array|max:30', 'items.*.id' => 'integer', 'items.*.quantity' => 'integer|min:1|max:50',
        ]);
        $event = Event::query()->where('app_id', $this->context->id())->findOrFail($data['event_id']);
        $subtotal = 0.0;
        foreach ($data['tickets'] ?? [] as $line) {
            $ticket = Ticket::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->findOrFail($line['id']);
            $subtotal += (float)$ticket->price * (int)$line['quantity'];
        }
        foreach ($data['items'] ?? [] as $line) {
            $item = EventItem::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->where('is_active',true)->findOrFail($line['id']);
            $subtotal += (float)$item->price * (int)$line['quantity'];
        }
        $result = $this->coupons->validateForCheckout($this->context->id(), $event->id, (int)$event->production_id, (int)$user->id, $data['coupon_code'], round($subtotal,2));
        return response()->json(['coupon' => [
            'code' => $result['code'],
            'discount_amount' => $result['discount_amount'],
            'subtotal' => $result['subtotal'],
            'total' => $result['total'],
        ]]);
    }

    private function validated(Request $request, int $organizationId, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'code' => 'nullable|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/',
            'event_id' => 'nullable|integer|exists:events,id',
            'discount_type' => $prefix.'in:percentage,fixed',
            'discount_value' => $prefix.'numeric|min:0.01|max:999999.99',
            'minimum_subtotal' => 'nullable|numeric|min:0|max:999999.99',
            'max_uses' => 'nullable|integer|min:1|max:1000000',
            'max_uses_per_user' => 'nullable|integer|min:1|max:1000',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'sometimes|boolean',
        ]);
        if (($data['discount_type'] ?? null) === 'percentage' && (float)($data['discount_value'] ?? 0) > 100) {
            abort(422, 'O desconto percentual não pode ultrapassar 100%.');
        }
        if (!empty($data['event_id'])) {
            Event::query()->where('app_id',$this->context->id())->where('production_id',$organizationId)->findOrFail($data['event_id']);
        }
        $data['minimum_subtotal'] = $data['minimum_subtotal'] ?? 0;
        $data['max_uses_per_user'] = $data['max_uses_per_user'] ?? 1;
        return $data;
    }

    private function uniqueCode(?string $requested, ?int $ignoreId = null): string
    {
        $base = strtoupper(trim((string)$requested));
        if ($base === '') $base = 'CUT'.strtoupper(Str::random(7));
        $code = $base; $suffix = 1;
        while (CommerceCoupon::query()->where('app_id',$this->context->id())->where('code',$code)->when($ignoreId,fn($q)=>$q->whereKeyNot($ignoreId))->exists()) {
            $code = substr($base,0,32).'-'.$suffix++;
        }
        return $code;
    }

    private function ownedProduction(Request $request, int $organizationId): Production
    {
        $userId = (int)$request->user()->id;
        return Production::query()->where('app_id',$this->context->id())->whereKey($organizationId)
            ->where(fn($q)=>$q->where('user_id',$userId)->orWhere('created_by',$userId))->firstOrFail();
    }

    private function ownedCoupon(int $organizationId, int $couponId): CommerceCoupon
    {
        return CommerceCoupon::query()->where('app_id',$this->context->id())->where('production_id',$organizationId)->findOrFail($couponId);
    }
}
