<?php

namespace App\Services\Payments\Statements;

use App\Contracts\Payments\ProviderFinancialStatementGateway;
use App\Services\MercadoPagoService;
use Carbon\CarbonImmutable;

class MercadoPagoFinancialStatementGateway implements ProviderFinancialStatementGateway
{
    private const REQUIRED_COLUMNS = [
        'DATE', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'RECORD_TYPE', 'DESCRIPTION',
        'NET_CREDIT_AMOUNT', 'NET_DEBIT_AMOUNT', 'SELLER_AMOUNT', 'GROSS_AMOUNT',
        'MP_FEE_AMOUNT', 'BALANCE_AMOUNT', 'PAYOUT_BANK_ACCOUNT_NUMBER', 'CURRENCY',
        'IS_RELEASED',
    ];

    public function __construct(private readonly MercadoPagoService $mercadoPago) {}

    public function name(): string
    {
        return 'mercadopago';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.mercadopago.access_token')) !== '';
    }

    public function ensureReportConfiguration(): void
    {
        $current = $this->mercadoPago->getReleaseReportConfiguration($this->token());
        $payload = $this->configurationPayload($current ?? []);

        if (! $current) {
            $this->mercadoPago->createReleaseReportConfiguration($this->token(), $payload);
            return;
        }

        $currentColumns = collect($current['columns'] ?? [])->pluck('key')->map(fn ($key) => strtoupper((string) $key))->filter()->values();
        $missingColumns = collect(self::REQUIRED_COLUMNS)->diff($currentColumns);
        $requiresUpdate = $missingColumns->isNotEmpty()
            || strtoupper((string) ($current['display_timezone'] ?? '')) !== 'GMT-03'
            || ! (bool) ($current['include_withdrawal_at_end'] ?? false)
            || ! (bool) ($current['check_available_balance'] ?? false);

        if ($requiresUpdate) {
            $this->mercadoPago->updateReleaseReportConfiguration($this->token(), $payload);
        }
    }

    public function requestReleasedMoneyReport(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->mercadoPago->requestReleaseReport(
            $this->token(),
            $from->utc()->format('Y-m-d\TH:i:s\Z'),
            $to->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    public function reportTask(string $taskId): array
    {
        return $this->mercadoPago->getReleaseReportTask($this->token(), $taskId);
    }

    public function findReport(?string $reportId, ?string $fileName = null): ?array
    {
        return $this->mercadoPago->searchReleaseReport($this->token(), $reportId, $fileName);
    }

    public function downloadReport(string $fileName): string
    {
        return $this->mercadoPago->downloadReleaseReport($this->token(), $fileName);
    }

    private function configurationPayload(array $current): array
    {
        $existingColumns = collect($current['columns'] ?? [])->pluck('key')->map(fn ($key) => strtoupper((string) $key))->filter();
        $columns = $existingColumns->merge(self::REQUIRED_COLUMNS)->unique()->values();

        return [
            'columns' => $columns->map(fn (string $key) => ['key' => $key])->all(),
            'file_name_prefix' => trim((string) ($current['file_name_prefix'] ?? '')) ?: 'peter-tecnet-released-money',
            'frequency' => is_array($current['frequency'] ?? null) ? $current['frequency'] : ['hour' => 0, 'value' => 1, 'type' => 'monthly'],
            'separator' => (string) ($current['separator'] ?? ';'),
            'display_timezone' => 'GMT-03',
            'include_withdrawal_at_end' => true,
            'check_available_balance' => true,
            'compensate_detail' => (bool) ($current['compensate_detail'] ?? true),
            'execute_after_withdrawal' => (bool) ($current['execute_after_withdrawal'] ?? false),
        ];
    }

    private function token(): string
    {
        return trim((string) config('services.mercadopago.access_token'));
    }
}
