<?php

namespace App\Services\Payments\Statements;

use App\Contracts\Payments\ProviderFinancialStatementGateway;
use App\Services\MercadoPagoService;
use Carbon\CarbonImmutable;

class MercadoPagoFinancialStatementGateway implements ProviderFinancialStatementGateway
{
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
        if ($this->mercadoPago->getReleaseReportConfiguration($this->token())) return;

        $this->mercadoPago->createReleaseReportConfiguration($this->token(), [
            'columns' => collect([
                'DATE', 'SOURCE_ID', 'EXTERNAL_REFERENCE', 'RECORD_TYPE', 'DESCRIPTION',
                'NET_CREDIT_AMOUNT', 'NET_DEBIT_AMOUNT', 'SELLER_AMOUNT', 'GROSS_AMOUNT',
                'MP_FEE_AMOUNT', 'BALANCE_AMOUNT', 'PAYOUT_BANK_ACCOUNT_NUMBER', 'CURRENCY',
                'IS_RELEASED',
            ])->map(fn (string $key) => ['key' => $key])->all(),
            'file_name_prefix' => 'peter-tecnet-released-money',
            'frequency' => ['hour' => 0, 'value' => 1, 'type' => 'monthly'],
            'separator' => ';',
            'include_withdrawal_at_end' => true,
            'check_available_balance' => true,
            'scheduled' => false,
        ]);
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

    private function token(): string
    {
        return trim((string) config('services.mercadopago.access_token'));
    }
}
