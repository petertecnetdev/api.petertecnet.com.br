<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Commerce\Services\OrderingSettingsService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderingSettingsController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderingSettingsService $settings,
    ) {}

    public function show(Request $request, int $establishment): JsonResponse
    {
        $est = $this->settings->owned($this->context->id(), $establishment, (int) $request->user()->id);
        return response()->json(['success' => true, 'data' => $this->settings->data($est)]);
    }

    public function update(Request $request, int $establishment): JsonResponse
    {
        $est = $this->settings->owned($this->context->id(), $establishment, (int) $request->user()->id);
        $data = $request->validate([
            'ordering_enabled'=>['sometimes','boolean'],
            'accepting_orders'=>['sometimes','boolean'],
            'delivery_enabled'=>['sometimes','boolean'],
            'pickup_enabled'=>['sometimes','boolean'],
            'dine_in_enabled'=>['sometimes','boolean'],
            'delivery_fee'=>['sometimes','numeric','min:0','max:9999.99'],
            'minimum_order'=>['sometimes','numeric','min:0','max:999999.99'],
            'estimated_delivery_minutes'=>['nullable','integer','min:1','max:1440'],
            'opening_hours'=>['nullable','array'],
            'payment_methods'=>['sometimes','array','max:3'],
            'payment_methods.*'=>[Rule::in(['pix','cash','card_on_delivery'])],
            'pix_key'=>['nullable','string','max:255'],
        ]);

        return response()->json(['success'=>true,'message'=>'Operação de pedidos atualizada.',
            'data'=>$this->settings->update($est, $data, (int) $request->user()->id)]);
    }

}
