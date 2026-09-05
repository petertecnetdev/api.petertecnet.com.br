<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevenueFunnelController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(Request $request, int $organizationId): JsonResponse
    {
        $organization = Establishment::query()
            ->whereKey($organizationId)
            ->where('app_id', $this->context->id())
            ->firstOrFail();

        $user = $request->user();
        abort_unless(
            $user && ($user->hasProfile('Administrador') || (int) $organization->user_id === (int) $user->id),
            403,
            'Sem permissão para acessar as métricas desta organização.'
        );

        $days = min(max((int) $request->query('days', 30), 1), 365);
        $since = now()->subDays($days);
        $orders = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('created_at', '>=', $since);

        $created = (clone $orders)->count();
        $paid = (clone $orders)->where('status', 'paid')->count();
        $pending = (clone $orders)->where('status', 'pending')->count();
        $cancelled = (clone $orders)->where('status', 'cancelled')->count();
        $expired = (clone $orders)->where('status', 'pending')->where('expires_at', '<', now())->count();
        $paidOrders = (clone $orders)->where('status', 'paid');
        $gross = (float) (clone $paidOrders)->sum('total');
        $platformRevenue = (float) (clone $paidOrders)->sum('platform_fee');
        $processorFees = (float) (clone $paidOrders)->sum('processor_fee');
        $discounts = (float) (clone $paidOrders)->sum('discount_amount');
        $producerNet = (float) (clone $paidOrders)->sum('producer_net');

        return response()->json([
            'period_days' => $days,
            'orders_created' => $created,
            'orders_paid' => $paid,
            'orders_pending' => $pending,
            'orders_cancelled' => $cancelled,
            'orders_expired_unpaid' => $expired,
            'checkout_conversion_rate' => $created > 0 ? round(($paid / $created) * 100, 2) : 0.0,
            'abandonment_rate' => $created > 0 ? round((($cancelled + $expired) / $created) * 100, 2) : 0.0,
            'gross_revenue' => round($gross, 2),
            'platform_revenue' => round($platformRevenue, 2),
            'processor_fees' => round($processorFees, 2),
            'discounts' => round($discounts, 2),
            'producer_net' => round($producerNet, 2),
            'average_paid_order' => $paid > 0 ? round($gross / $paid, 2) : 0.0,
        ]);
    }
}
