<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderingSettingsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(Request $request, int $establishment): JsonResponse
    {
        $est = $this->owned($request, $establishment);
        return response()->json(['success' => true, 'data' => $this->data($est)]);
    }

    public function update(Request $request, int $establishment): JsonResponse
    {
        $est = $this->owned($request, $establishment);
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

        $currentMethods = $this->decode($est->payment_methods, []);
        $methods = array_key_exists('payment_methods', $data)
            ? array_values(array_unique($data['payment_methods'] ?? []))
            : $currentMethods;
        $providerConfigured = trim((string) config('services.mercadopago.access_token')) !== '';
        $pixKey = trim((string) ($data['pix_key'] ?? $est->pix_key ?? ''));
        abort_if(in_array('pix', $methods, true) && ! $providerConfigured && $pixKey === '', 422, 'Para aceitar Pix, configure um provedor de pagamento na API ou informe uma chave Pix do estabelecimento.');

        if (array_key_exists('opening_hours', $data)) $data['opening_hours'] = json_encode($data['opening_hours']);
        if (array_key_exists('payment_methods', $data)) $data['payment_methods'] = json_encode($methods);
        $data['updated_by'] = $request->user()->id;
        $est->forceFill($data)->save();

        return response()->json(['success'=>true,'message'=>'Operação de pedidos atualizada.','data'=>$this->data($est->fresh())]);
    }

    private function owned(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function data(Establishment $est): array
    {
        $providerConfigured = trim((string) config('services.mercadopago.access_token')) !== '';
        $fallbackMethods = $providerConfigured || trim((string) ($est->pix_key ?? '')) !== ''
            ? ['pix','cash','card_on_delivery']
            : ['cash','card_on_delivery'];

        return [
            'establishment' => $est->only(['id','name','fantasy','slug','logo']),
            'ordering_enabled' => (bool)($est->ordering_enabled ?? true),
            'accepting_orders' => (bool)($est->accepting_orders ?? true),
            'delivery_enabled' => (bool)($est->delivery_enabled ?? true),
            'pickup_enabled' => (bool)($est->pickup_enabled ?? true),
            'dine_in_enabled' => (bool)($est->dine_in_enabled ?? false),
            'delivery_fee' => (float)($est->delivery_fee ?? 0),
            'minimum_order' => (float)($est->minimum_order ?? 0),
            'estimated_delivery_minutes' => $est->estimated_delivery_minutes ? (int)$est->estimated_delivery_minutes : 45,
            'opening_hours' => $this->decode($est->opening_hours, []),
            'payment_methods' => $this->decode($est->payment_methods, $fallbackMethods),
            'pix_key' => (string)($est->pix_key ?? ''),
            'payment_provider_configured' => $providerConfigured,
            // Kept temporarily for clients that still read the legacy key.
            'mercadopago_configured' => $providerConfigured,
        ];
    }

    private function decode($value, array $fallback): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}
