<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use App\Services\Commerce\CommerceFulfillmentService;
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

    public function credential(Request $request, string $publicId): JsonResponse
    {
        $order = $this->buyerOrder($request, $publicId);

        return response()->json([
            'success' => true,
            'data' => [
                'fulfillment_status' => $order->fulfillment_status,
                'claim' => $this->fulfillment->claimPayload($order),
            ],
        ]);
    }

    public function updateStatus(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                CommerceFulfillmentService::PREPARING,
                CommerceFulfillmentService::READY,
            ])],
        ]);

        $order = $this->sellerOrder($request, $publicId);
        $updated = $this->fulfillment->transition(
            $order,
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

    public function verify(Request $request, string $publicId): JsonResponse
    {
        $data = $this->credentialData($request);
        $order = $this->sellerOrder($request, $publicId);
        $verified = $this->fulfillment->verify(
            $order,
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

    public function redeem(Request $request, string $publicId): JsonResponse
    {
        $data = $this->credentialData($request);
        $order = $this->sellerOrder($request, $publicId);
        $redeemed = $this->fulfillment->redeem(
            $order,
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

    public function history(Request $request, string $publicId): JsonResponse
    {
        $order = $this->sellerOrder($request, $publicId);
        $limit = min(max((int) $request->query('limit', 30), 1), 100);

        return response()->json([
            'success' => true,
            'data' => $this->fulfillment->history($order, $limit),
        ]);
    }

    private function credentialData(Request $request): array
    {
        return $request->validate([
            'token' => ['nullable', 'string', 'max:128', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:32', 'required_without:token'],
        ]);
    }

    private function buyerOrder(Request $request, string $publicId): Order
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('client_id', $request->user()->id)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();
    }

    private function sellerOrder(Request $request, string $publicId): Order
    {
        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();

        $this->manageable($request, (int) $order->entity_id);

        return $order;
    }

    private function manageable(Request $request, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->forApplication($this->context->id())
            ->whereKey($establishmentId)
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
            ->forApplication($this->context->id())
            ->whereKey($order->entity_id)
            ->first();

        return [
            'id' => $order->id,
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfillment' => $order->fulfillment,
            'fulfillment_status' => $order->fulfillment_status,
            'total_price' => (float) $order->total_price,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'fulfilled_at' => optional($order->fulfilled_at)->toIso8601String(),
            'establishment' => $establishment?->only([
                'id', 'name', 'fantasy', 'slug', 'logo', 'address', 'city', 'uf', 'phone',
            ]),
            'items' => $order->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
            ])->values(),
        ];
    }
}
