<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\CommerceFulfillmentService;
use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceFulfillmentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceFulfillmentService $fulfillment,
    ) {}

    public function credential(Request $request, int $order): JsonResponse
    {
        $model = $this->buyerOrder($request, $order);

        return response()->json([
            'success' => true,
            'data' => [
                'fulfillment_status' => $this->fulfillment->presentedStatus($model),
                'claim' => $this->fulfillment->claimPayload($model),
            ],
        ]);
    }

    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                CommerceFulfillmentService::PREPARING,
                CommerceFulfillmentService::READY,
            ])],
        ]);

        $model = $this->sellerOrder($request, $order);
        $updated = $this->fulfillment->transition(
            $model,
            $data['status'],
            $request->user(),
            $this->fulfillment->requestContext($request)
        );

        return response()->json([
            'success' => true,
            'message' => $data['status'] === CommerceFulfillmentService::READY
                ? 'Pedido marcado como pronto.'
                : 'Preparo do pedido iniciado.',
            'data' => $this->serialize($updated),
        ]);
    }

    public function verify(Request $request, int $order): JsonResponse
    {
        $data = $this->credentialData($request);
        $model = $this->sellerOrder($request, $order);
        $verified = $this->fulfillment->verify(
            $model,
            $data['token'] ?? null,
            $data['code'] ?? null,
            $request->user(),
            $this->fulfillment->requestContext($request)
        );

        return response()->json([
            'success' => true,
            'message' => 'Comprovante validado. Confira o pedido antes de confirmar o recebimento.',
            'data' => $this->serialize($verified),
        ]);
    }

    public function redeem(Request $request, int $order): JsonResponse
    {
        $data = $this->credentialData($request);
        $model = $this->sellerOrder($request, $order);
        $redeemed = $this->fulfillment->redeem(
            $model,
            $data['token'] ?? null,
            $data['code'] ?? null,
            $request->user(),
            $this->fulfillment->requestContext($request)
        );

        return response()->json([
            'success' => true,
            'message' => $redeemed->fulfillment === 'delivery'
                ? 'Entrega confirmada.'
                : 'Retirada confirmada.',
            'data' => $this->serialize($redeemed),
        ]);
    }

    public function history(Request $request, int $order): JsonResponse
    {
        $model = $this->sellerOrder($request, $order);
        $limit = min(max((int) $request->query('limit', 30), 1), 100);

        return response()->json([
            'success' => true,
            'data' => $this->fulfillment->history($model, $limit),
        ]);
    }

    private function credentialData(Request $request): array
    {
        return $request->validate([
            'token' => ['nullable', 'string', 'max:128', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:32', 'required_without:token'],
        ]);
    }

    private function buyerOrder(Request $request, int $order): Order
    {
        return Order::query()
            ->whereKey($order)
            ->where('app_id', $this->context->id())
            ->where('client_id', $request->user()->id)
            ->where('entity_name', 'establishment')
            ->with(['items.item'])
            ->firstOrFail();
    }

    private function sellerOrder(Request $request, int $order): Order
    {
        $model = Order::query()
            ->whereKey($order)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->with(['items.item'])
            ->firstOrFail();

        $this->manageable($request, (int) $model->entity_id);

        return $model;
    }

    private function manageable(Request $request, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->whereKey($establishmentId)
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $userId = (int) $request->user()->id;
        $isOwner = (int) $establishment->user_id === $userId
            || (int) $establishment->created_by === $userId;
        $isEmployee = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($isOwner || $isEmployee, 403, 'Você não possui acesso a esta operação.');

        return $establishment;
    }

    private function serialize(Order $order): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->first();

        return [
            'id' => $order->id,
            // Compatibility presentation for clients that previously used a UUID.
            // The canonical API contract is the numeric order id.
            'public_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status ?: 'pending',
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfillment' => $order->fulfillment,
            'fulfillment_status' => $this->fulfillment->presentedStatus($order),
            'subtotal' => (float) ($order->subtotal ?? 0),
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'total_price' => (float) $order->total_price,
            'delivery_address' => $order->delivery_address,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'notes' => $order->notes,
            'fulfilled_at' => $order->status === 'completed'
                ? optional($order->attended_at)->toIso8601String()
                : null,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
            'status_updated_at' => $order->status_updated_at
                ? \Carbon\Carbon::parse($order->status_updated_at)->toIso8601String()
                : null,
            'establishment' => $establishment?->only([
                'id', 'name', 'fantasy', 'slug', 'logo', 'address', 'city', 'uf', 'phone',
            ]),
            'items' => $order->items->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
                'notes' => $line->notes ?? null,
            ])->values(),
        ];
    }
}
