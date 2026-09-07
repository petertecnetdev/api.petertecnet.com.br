<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ApplicationAdminCommerceController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Order::query()
            ->where('app_id', $this->context->id())
            ->with([
                'client:id,first_name,last_name,email',
                'creator:id,first_name,last_name,email',
            ]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('customer_name', 'like', '%'.$term.'%')
                    ->orWhere('customer_email', 'like', '%'.$term.'%')
                    ->orWhere('customer_phone', 'like', '%'.$term.'%')
                    ->orWhereHas('client', fn ($client) => $client->where('email', 'like', '%'.$term.'%'));
            });
        }

        if (! empty($data['payment_status'])) $query->where('payment_status', $data['payment_status']);
        if (! empty($data['status'])) $query->where('status', $data['status']);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function finance(): JsonResponse
    {
        $appId = $this->context->id();
        $base = DB::table('orders')->where('app_id', $appId);
        $paid = (clone $base)->whereIn('payment_status', ['paid', 'approved', 'completed']);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => [
                'orders_total' => (clone $base)->count(),
                'orders_paid' => (clone $paid)->count(),
                'gmv' => (float) (clone $base)->sum('total_price'),
                'paid_volume' => (float) (clone $paid)->sum('total_price'),
                'pending_volume' => (float) (clone $base)->whereIn('payment_status', ['pending', 'waiting', 'processing'])->sum('total_price'),
                'refunded_volume' => (float) (clone $base)->whereIn('payment_status', ['refunded', 'chargeback', 'charged_back'])->sum('total_price'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function updateOrder(Request $request, int $order): JsonResponse
    {
        $model = Order::query()->where('app_id', $this->context->id())->findOrFail($order);
        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', 'max:50'],
            'payment_status' => ['sometimes', 'required', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'cancelled_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $model->fill($data)->save();

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $model->fresh(),
        ]);
    }
}
