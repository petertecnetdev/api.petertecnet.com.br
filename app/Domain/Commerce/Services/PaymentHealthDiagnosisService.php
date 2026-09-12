<?php

namespace App\Domain\Commerce\Services;

final class PaymentHealthDiagnosisService
{
    public function diagnoseCurrent(array $current): array
    {
        $critical = (int) ($current['critical_orders'] ?? 0);
        $expired = (int) ($current['expired_orders'] ?? 0);
        $providerPending = (int) ($current['provider_pending_payments'] ?? 0);
        $atRiskVolume = (float) ($current['at_risk_volume'] ?? 0);

        if ($expired > 0 && $providerPending === 0) {
            return $this->diagnosis(
                'checkout_expiration_or_abandonment',
                'Checkout expirado ou abandono',
                'high',
                'Há pedidos expirados ainda pendentes sem pagamentos pendentes no provedor. Priorize expiração limpa, recuperação de carrinho e clareza do checkout.'
            );
        }

        if ($providerPending > 0 && $providerPending >= max(1, $critical)) {
            return $this->diagnosis(
                'provider_or_payment_completion',
                'Provedor ou conclusão do pagamento',
                'medium',
                'A maior parte do backlog crítico também está pendente no provedor. Verifique latência, status PIX/cartão e retorno do gateway antes de alterar fulfillment.'
            );
        }

        if ($critical > $providerPending) {
            return $this->diagnosis(
                'confirmation_or_reconciliation',
                'Confirmação, webhook ou reconciliação',
                'medium',
                'Existem mais pedidos críticos do que pagamentos pendentes no provedor. Investigue confirmação local, webhook e reconciliação antes da emissão do ingresso.'
            );
        }

        if ($atRiskVolume > 0) {
            return $this->diagnosis(
                'pending_backlog',
                'Backlog pendente',
                'low',
                'Existe volume financeiro envelhecendo, mas os sinais atuais não isolam uma causa dominante. Acompanhe provedor, webhook e expiração antes de agir.'
            );
        }

        return $this->diagnosis(
            'healthy',
            'Sem gargalo dominante',
            'high',
            'Não há evidência atual de um gargalo financeiro relevante no fluxo pendente.'
        );
    }

    public function diagnoseIncidents(array $incidents): array
    {
        if (is_array($incidents['active'] ?? null)) {
            $incidents['active']['diagnosis'] = $this->diagnoseIncident($incidents['active']);
        }

        $incidents['recent'] = array_map(
            function (array $incident): array {
                $incident['diagnosis'] = $this->diagnoseIncident($incident);

                return $incident;
            },
            is_array($incidents['recent'] ?? null) ? $incidents['recent'] : []
        );

        return $incidents;
    }

    private function diagnoseIncident(array $incident): array
    {
        $critical = (int) ($incident['peak_critical_orders'] ?? 0);
        $providerPending = (int) ($incident['peak_provider_pending_payments'] ?? 0);

        if ($providerPending > 0 && $providerPending >= max(1, $critical)) {
            return $this->diagnosis(
                'provider_or_payment_completion',
                'Provedor ou conclusão do pagamento',
                'medium',
                'O pico do incidente ficou concentrado em pagamentos ainda pendentes no provedor.'
            );
        }

        if ($critical > $providerPending) {
            return $this->diagnosis(
                'confirmation_or_reconciliation',
                'Confirmação, webhook ou reconciliação',
                'medium',
                'O pico de pedidos críticos superou os pagamentos pendentes no provedor, indicando provável atraso após a etapa do gateway.'
            );
        }

        return $this->diagnosis(
            'undetermined',
            'Causa não isolada',
            'low',
            'O histórico agregado não permite apontar uma causa dominante com segurança.'
        );
    }

    private function diagnosis(string $code, string $label, string $confidence, string $guidance): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'confidence' => $confidence,
            'guidance' => $guidance,
            'is_inference' => true,
        ];
    }
}
