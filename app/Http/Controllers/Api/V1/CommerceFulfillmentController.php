<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommerceFulfillmentController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function verify(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $order = $this->findOrder($publicId);
        $this->manageable($request, (int) $order->entity_id);
        $this->assertClaim($order, $data['token']);

        return response()->json(['success' => true, 'data' => $this->serialize($order)]);
    }

    public function redeem(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        $order = DB::transaction(function () use ($request, $publicId, $data) {
            $order = Order::query()
                ->where('app_id', $this->context->id())
                ->where('public_id', $publicId)
                ->where('type', 'commerce')
                ->with(['items.item'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->manageable($request, (int) $order->entity_id);
            $this->assertClaim($order, $data['token']);
            abort_if(in_array($order->fulfillment_status, ['fulfilled', 'delivered'], true), 409, 'Este QR Code já foi utilizado.');

            $fulfillmentStatus = $order->fulfillment === 'delivery' ? 'delivered' : 'fulfilled';
            $order->forceFill([
                'fulfillment_status' => $fulfillmentStatus,
                'fulfilled_at' => now(),
                'fulfilled_by' => $request->user()->id,
                'status' => 'completed',
                'status_updated_at' => now(),
                'attended_at' => now(),
            ])->save();

            return $order->fresh(['items.item']);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => $order->fulfillment === 'delivery' ? 'Entrega confirmada.' : 'Retirada confirmada.',
            'data' => $this->serialize($order),
        ]);
    }

    private function assertClaim(Order $order, string $token): void
    {
        abort_unless($order->payment_status === 'paid', 422, 'Pagamento ainda não confirmado.');
        abort_unless(hash_equals($this->claimToken($order), $token), 403, 'QR Code inválido.');
    }

    private function findOrder(string $publicId): Order
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();
    }

    private function manageable(Request $request, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->whereKey($establishmentId)
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $userId = (int) $request->user()->id;
        $isOwner = (int) $establishment->user_id === $userId || (int) $establishment->created_by === $userId;
        $isEmployee = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($isOwner || $isEmployee, 403, 'Você não possui acesso a esta compra.');
        return $establishment;
    }

    private function serialize(Order $order): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->first();

        return [
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment' => $order->fulfillment,
            'fulfillment_status' => $order->fulfillment_status,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'total_price' => (float) $order->total_price,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'fulfilled_at' => optional($order->fulfilled_at)->toIso8601String(),
            'establishment' => $establishment?->only(['id', 'name', 'fantasy', 'slug']),
            'items' => $order->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
            ])->values(),
        ];
    }

    private function claimToken(Order $order): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$order->public_id, $order->id, $order->app_id, $order->client_id]),
            (string) config('app.key')
        );
    }
}
