<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\CutinappOrder;
use App\Models\Production;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CutinappOrderHistoryController extends Controller
{
    private const APP = 'cutinapp';

    public function purchases(Request $request)
    {
        $orders = CutinappOrder::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'event:id,title,slug,start_date,end_date',
                'production:id,name,slug',
                'items',
                'payments' => fn ($query) => $query->latest('id'),
            ])
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));

        return response()->json($orders);
    }

    public function purchase(Request $request, string $publicId)
    {
        $order = $this->findOrder($publicId);
        abort_unless((int) $order->user_id === (int) $request->user()->id, 403);

        return response()->json(['order' => $this->decorate($order)]);
    }

    public function producerSales(Request $request, int $productionId)
    {
        $this->ownedProduction($request, $productionId);

        $query = CutinappOrder::query()
            ->where('production_id', $productionId)
            ->with([
                'user:id,first_name,last_name,email',
                'event:id,title,slug,start_date,end_date',
                'items',
                'payments' => fn ($paymentQuery) => $paymentQuery->latest('id'),
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('event_id')) {
            $query->where('event_id', $request->integer('event_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }
        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            $query->where(function ($builder) use ($term) {
                $builder->where('public_id', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($userQuery) use ($term) {
                        $userQuery->where('email', 'like', "%{$term}%")
                            ->orWhere('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%");
                    });
            });
        }

        $orders = $query->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 30), 1), 100));

        $summaryBase = CutinappOrder::query()->where('production_id', $productionId);
        $summary = [
            'paid_count' => (clone $summaryBase)->where('status', 'paid')->count(),
            'pending_count' => (clone $summaryBase)->where('status', 'pending')->count(),
            'cancelled_count' => (clone $summaryBase)->whereIn('status', ['cancelled', 'refunded', 'charged_back'])->count(),
            'gross_paid' => round((float) (clone $summaryBase)->where('status', 'paid')->sum('total'), 2),
            'platform_fees' => round((float) (clone $summaryBase)->where('status', 'paid')->sum('platform_fee'), 2),
            'processor_fees' => round((float) (clone $summaryBase)->where('status', 'paid')->sum('processor_fee'), 2),
            'producer_net' => round((float) (clone $summaryBase)->where('status', 'paid')->sum('producer_net'), 2),
        ];

        return response()->json(['orders' => $orders, 'summary' => $summary]);
    }

    public function producerSale(Request $request, int $productionId, string $publicId)
    {
        $this->ownedProduction($request, $productionId);
        $order = $this->findOrder($publicId);
        abort_unless((int) $order->production_id === $productionId, 404);

        return response()->json(['order' => $this->decorate($order)]);
    }

    public function receipt(Request $request, string $publicId)
    {
        $order = $this->findOrder($publicId);
        $this->authorizeOrderAccess($request, $order);

        return response()->json(['receipt' => $this->receiptData($order)]);
    }

    public function receiptPdf(Request $request, string $publicId)
    {
        $order = $this->findOrder($publicId);
        $this->authorizeOrderAccess($request, $order);
        $receipt = $this->receiptData($order);

        return Pdf::loadView('pdf.cutinapp-receipt', ['receipt' => $receipt])
            ->setPaper('a4')
            ->download('cutinapp-recibo-' . substr($order->public_id, 0, 8) . '.pdf');
    }

    private function findOrder(string $publicId): CutinappOrder
    {
        return CutinappOrder::query()
            ->where('public_id', $publicId)
            ->with([
                'user:id,first_name,last_name,email',
                'event:id,title,slug,start_date,end_date',
                'production:id,name,slug',
                'items',
                'payments' => fn ($query) => $query->latest('id'),
            ])
            ->firstOrFail();
    }

    private function decorate(CutinappOrder $order): array
    {
        $data = $order->toArray();
        $data['passes'] = DB::table('event_passes as ep')
            ->join('cutinapp_order_items as oi', 'oi.id', '=', 'ep.cutinapp_order_item_id')
            ->where('oi.order_id', $order->id)
            ->select('ep.id', 'ep.ticket_id', 'ep.status', 'ep.checked_in_at', 'ep.created_at')
            ->orderBy('ep.id')
            ->get();
        $data['fulfillment_status'] = data_get($order->metadata, 'fulfillment_status');

        return $data;
    }

    private function receiptData(CutinappOrder $order): array
    {
        $payment = $order->payments->first();
        $buyerName = trim(implode(' ', array_filter([
            $order->user?->first_name,
            $order->user?->last_name,
        ])));

        return [
            'number' => strtoupper(substr($order->public_id, 0, 8)),
            'public_id' => $order->public_id,
            'status' => $order->status,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'paid_at' => optional($order->paid_at)->toIso8601String(),
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'total' => $order->total,
            'payment_method' => $order->payment_method,
            'event' => $order->event,
            'production' => $order->production,
            'buyer' => [
                'name' => $buyerName !== '' ? $buyerName : ($order->user?->email ?? 'Cliente'),
                'email' => $order->user?->email,
            ],
            'items' => $order->items,
            'payment' => $payment ? [
                'provider' => $payment->provider,
                'status' => $payment->status,
                'provider_payment_id' => $payment->provider_payment_id,
                'amount' => $payment->amount,
                'created_at' => optional($payment->created_at)->toIso8601String(),
            ] : null,
            'fulfillment_status' => data_get($order->metadata, 'fulfillment_status'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function authorizeOrderAccess(Request $request, CutinappOrder $order): void
    {
        if ((int) $order->user_id === (int) $request->user()->id) return;
        $this->ownedProduction($request, (int) $order->production_id);
    }

    private function ownedProduction(Request $request, int $productionId): Production
    {
        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->firstOrFail();
        $production = Production::query()
            ->where('id', $productionId)
            ->where('app_id', $application->id)
            ->where('app_slug', self::APP)
            ->firstOrFail();

        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless($admin || (int) $production->user_id === (int) $request->user()->id, 403);

        return $production;
    }
}
