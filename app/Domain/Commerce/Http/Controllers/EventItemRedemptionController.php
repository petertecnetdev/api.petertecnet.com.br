<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\EventItemRedemptionService;
use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class EventItemRedemptionController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventItemRedemptionService $redemptions,
    ) {}

    public function credential(Request $request, string $publicId)
    {
        $order = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->with(['items', 'event', 'production'])
            ->firstOrFail();

        abort_unless(
            (int) $order->user_id === (int) $request->user()->id || $this->ownsProduction($request, (int) $order->production_id),
            403
        );

        return response()->json([
            'credential' => $this->redemptions->credential($order),
        ]);
    }

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:180',
            'event_id' => 'required|integer|exists:events,id',
        ]);

        return response()->json(
            $this->redemptions->redeem($request->user(), $data['token'], (int) $data['event_id'])
        );
    }

    private function ownsProduction(Request $request, int $productionId): bool
    {
        $production = Production::query()
            ->where('app_id', $this->context->id())
            ->find($productionId);
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');

        return (bool) ($production && ($admin || (int) $production->user_id === (int) $request->user()->id));
    }
}
