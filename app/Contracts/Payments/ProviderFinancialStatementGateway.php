<?php

namespace App\Contracts\Payments;

use Carbon\CarbonImmutable;

interface ProviderFinancialStatementGateway
{
    public function name(): string;
    public function isConfigured(): bool;
    public function ensureReportConfiguration(): void;
    public function requestReleasedMoneyReport(CarbonImmutable $from, CarbonImmutable $to): array;
    public function reportTask(string $taskId): array;
    public function findReport(?string $reportId, ?string $fileName = null): ?array;
    public function downloadReport(string $fileName): string;
}
