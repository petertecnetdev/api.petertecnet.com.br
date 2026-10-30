<?php

namespace App\Domain\Commerce\Services;

use App\Models\Establishment;

final class OrderingSettingsService
{
    public function owned(int $appId, int $establishmentId, int $actorId): Establishment
    {
        return Establishment::query()
            ->whereKey($establishmentId)
            ->forApplication($appId)
            ->where('user_id', $actorId)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    public function update(Establishment $est, array $data, int $actorId): array
    {
        $currentMethods = $this->decode($est->payment_methods, []);
        $methods = array_key_exists('payment_methods', $data)
            ? array_values(array_unique($data['payment_methods'] ?? []))
            : $currentMethods;
        $providerConfigured = trim((string) config('services.mercadopago.access_token')) !== '';
        $pixKey = trim((string) ($data['pix_key'] ?? $est->pix_key ?? ''));
        abort_if(in_array('pix', $methods, true) && ! $providerConfigured && $pixKey === '', 422, 'Para aceitar Pix, configure um provedor de pagamento na API ou informe uma chave Pix do estabelecimento.');

        if (array_key_exists('opening_hours', $data)) $data['opening_hours'] = json_encode($data['opening_hours']);
        if (array_key_exists('payment_methods', $data)) $data['payment_methods'] = json_encode($methods);
        $data['updated_by'] = $actorId;
        $est->forceFill($data)->save();

        return $this->data($est->fresh());
    }

    public function data(Establishment $est): array
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
