<?php

namespace App\Services\Payments;

use App\Models\EcosystemPayment;
use App\Models\FinancialLedgerEntry;
use App\Models\ProviderStatementEntry;
use App\Models\ProviderStatementReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ProviderFinancialStatementService
{
    public function __construct(private readonly ProviderFinancialStatementManager $providers) {}

    public function maintain(string $provider = 'mercadopago', bool $forceCurrent = false): array
    {
        if (! $this->tablesReady()) {
            return ['configured' => false, 'requested' => 0, 'checked' => 0, 'imported' => 0, 'entries' => 0, 'errors' => 0];
        }

        $gateway = $this->providers->for($provider);
        if (! $gateway->isConfigured()) {
            return ['configured' => false, 'requested' => 0, 'checked' => 0, 'imported' => 0, 'entries' => 0, 'errors' => 0];
        }

        $stats = ['configured' => true, 'requested' => 0, 'checked' => 0, 'imported' => 0, 'entries' => 0, 'errors' => 0];

        try {
            $gateway->ensureReportConfiguration();
            $now = CarbonImmutable::now();
            $windows = [
                [
                    'key' => 'hour:' . $now->format('Y-m-d-H'),
                    'from' => $now->startOfDay(),
                    'to' => $now,
                ],
                [
                    'key' => 'final:' . $now->subDay()->format('Y-m-d'),
                    'from' => $now->subDay()->startOfDay(),
                    'to' => $now->subDay()->endOfDay(),
                ],
            ];

            foreach ($windows as $window) {
                if ($this->requestWindow($provider, $window['key'], $window['from'], $window['to'], $forceCurrent)) {
                    $stats['requested']++;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
            $stats['errors']++;
        }

        $sync = $this->syncPending($provider);
        foreach (['checked', 'imported', 'entries', 'errors'] as $key) {
            $stats[$key] += $sync[$key];
        }

        return $stats;
    }

    public function requestWindow(
        string $provider,
        string $windowKey,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $force = false,
    ): bool {
        $gateway = $this->providers->for($provider);
        $existing = ProviderStatementReport::query()
            ->where('provider', $provider)
            ->where('report_type', 'released_money')
            ->where('window_key', $windowKey)
            ->first();

        if ($existing && ! $force) return false;
        if ($existing && $force && in_array($existing->status, ['pending', 'processing'], true)) return false;

        $remote = $gateway->requestReleasedMoneyReport($from, $to);
        $taskId = (string) ($remote['id'] ?? '');
        if ($taskId === '') throw new RuntimeException('O provedor não retornou o ID da tarefa do relatório financeiro.');

        $report = $existing ?: new ProviderStatementReport();
        $report->fill([
            'provider' => $provider,
            'report_type' => 'released_money',
            'window_key' => $windowKey,
            'provider_task_id' => $taskId,
            'provider_report_id' => isset($remote['report_id']) ? (string) $remote['report_id'] : null,
            'file_name' => $remote['file_name'] ?? null,
            'period_start' => $from,
            'period_end' => $to,
            'status' => strtolower((string) ($remote['status'] ?? 'pending')),
            'requested_at' => now(),
            'processed_at' => null,
            'imported_at' => null,
            'error_message' => null,
            'metadata' => $this->safeMetadata($remote),
        ])->save();

        return true;
    }

    public function syncPending(?string $provider = null, int $limit = 25): array
    {
        if (! $this->tablesReady()) {
            return ['checked' => 0, 'imported' => 0, 'entries' => 0, 'errors' => 0];
        }

        $query = ProviderStatementReport::query()
            ->whereIn('status', ['pending', 'processing', 'processed', 'ready', 'error'])
            ->orderBy('requested_at');
        if ($provider) $query->where('provider', $provider);

        $stats = ['checked' => 0, 'imported' => 0, 'entries' => 0, 'errors' => 0];
        foreach ($query->limit(max(1, min($limit, 100)))->get() as $report) {
            $stats['checked']++;
            try {
                $entries = $this->syncReport($report);
                if ($report->fresh()->status === 'imported') {
                    $stats['imported']++;
                    $stats['entries'] += $entries;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $report->forceFill([
                    'status' => 'error',
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
                $stats['errors']++;
            }
        }

        return $stats;
    }

    public function syncReport(ProviderStatementReport $report): int
    {
        $gateway = $this->providers->for($report->provider);
        $task = $gateway->reportTask((string) $report->provider_task_id);
        $status = strtolower((string) ($task['status'] ?? $report->status));
        $reportId = isset($task['report_id']) && $task['report_id'] !== null ? (string) $task['report_id'] : $report->provider_report_id;
        $fileName = $task['file_name'] ?? $report->file_name;

        if (! in_array($status, ['processed', 'ready', 'success', 'completed'], true)) {
            $report->forceFill([
                'status' => $status ?: 'processing',
                'provider_report_id' => $reportId,
                'file_name' => $fileName,
                'metadata' => array_merge($report->metadata ?? [], ['last_task' => $this->safeMetadata($task)]),
                'error_message' => null,
            ])->save();
            return 0;
        }

        if (! $fileName) {
            $remoteReport = $gateway->findReport($reportId);
            $fileName = $remoteReport['file_name'] ?? null;
            if ($remoteReport && ! $reportId && isset($remoteReport['id'])) $reportId = (string) $remoteReport['id'];
        }

        if (! $fileName) {
            $report->forceFill([
                'status' => 'processed',
                'provider_report_id' => $reportId,
                'processed_at' => $report->processed_at ?: now(),
                'error_message' => 'Relatório processado pelo provedor, aguardando nome do arquivo para importação.',
            ])->save();
            return 0;
        }

        $csv = $gateway->downloadReport($fileName);
        $count = $this->importCsv($report, $csv);
        $report->forceFill([
            'status' => 'imported',
            'provider_report_id' => $reportId,
            'file_name' => $fileName,
            'processed_at' => $report->processed_at ?: now(),
            'imported_at' => now(),
            'error_message' => null,
            'metadata' => array_merge($report->metadata ?? [], ['last_task' => $this->safeMetadata($task), 'entry_count' => $count]),
        ])->save();

        return $count;
    }

    public function importCsv(ProviderStatementReport $report, string $csv): int
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) return 0;

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $headers = array_map(fn ($value) => strtoupper(trim((string) $value, " \t\n\r\0\x0B\"")), str_getcsv(array_shift($lines), $delimiter));
        $count = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line, $delimiter);
            if (count($values) !== count($headers)) continue;
            $row = array_combine($headers, $values);
            if (! is_array($row)) continue;

            $entry = $this->importRow($report, $row);
            if ($entry) $count++;
        }

        return $count;
    }

    public function snapshot(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?string $provider = null): array
    {
        if (! $this->tablesReady()) return $this->emptySnapshot();

        $entries = ProviderStatementEntry::query();
        if ($provider) $entries->where('provider', $provider);
        if ($from) $entries->where('occurred_at', '>=', $from);
        if ($to) $entries->where('occurred_at', '<=', $to);
        $periodEntries = $entries->get();

        $latestBalanceQuery = ProviderStatementEntry::query()->whereNotNull('balance_amount')->orderByDesc('occurred_at')->orderByDesc('id');
        if ($provider) $latestBalanceQuery->where('provider', $provider);
        $latestBalance = $latestBalanceQuery->first();

        $withdrawals = $periodEntries->filter(fn (ProviderStatementEntry $entry) =>
            strtolower((string) $entry->description) === 'payout'
            || str_contains(strtolower((string) $entry->record_type), 'withdrawal')
        );
        $releases = $periodEntries->filter(fn (ProviderStatementEntry $entry) =>
            strtolower((string) $entry->record_type) === 'release' && (float) $entry->net_credit_amount > 0
        );

        $lastReport = ProviderStatementReport::query()
            ->when($provider, fn ($query) => $query->where('provider', $provider))
            ->latest('imported_at')->first();

        $settledPayments = EcosystemPayment::query()->where('settlement_status', 'settled');
        if ($provider) $settledPayments->where('provider', $provider);

        return [
            'provider' => $provider,
            'current_balance' => $latestBalance?->balance_amount !== null ? (float) $latestBalance->balance_amount : null,
            'balance_at' => $latestBalance?->occurred_at?->toIso8601String(),
            'released_net' => round((float) $releases->sum('net_credit_amount') - (float) $releases->sum('net_debit_amount'), 2),
            'bank_withdrawals' => round((float) $withdrawals->sum('net_debit_amount'), 2),
            'bank_withdrawal_count' => $withdrawals->count(),
            'period_credits' => round((float) $periodEntries->sum('net_credit_amount'), 2),
            'period_debits' => round((float) $periodEntries->sum('net_debit_amount'), 2),
            'settled_payment_count' => $settledPayments->count(),
            'unmatched_release_entries' => $periodEntries->where('record_type', 'release')->filter(fn ($entry) => ! data_get($entry->raw, '_matched_payment_id'))->count(),
            'last_import_at' => $lastReport?->imported_at?->toIso8601String(),
            'last_report_status' => $lastReport?->status,
            'report_errors' => ProviderStatementReport::query()->when($provider, fn ($query) => $query->where('provider', $provider))->where('status', 'error')->count(),
            'entry_count' => $periodEntries->count(),
        ];
    }

    private function importRow(ProviderStatementReport $report, array $row): ?ProviderStatementEntry
    {
        $sourceId = $this->value($row, 'SOURCE_ID');
        $externalReference = $this->value($row, 'EXTERNAL_REFERENCE');
        $recordType = strtolower($this->value($row, 'RECORD_TYPE'));
        $description = strtolower($this->value($row, 'DESCRIPTION'));
        $dateValue = $this->value($row, 'DATE');
        $occurredAt = $dateValue !== '' ? CarbonImmutable::parse($dateValue) : null;
        $credit = $this->number($this->value($row, 'NET_CREDIT_AMOUNT'));
        $debit = $this->number($this->value($row, 'NET_DEBIT_AMOUNT'));
        $balanceRaw = $this->value($row, 'BALANCE_AMOUNT');
        $bankRaw = $this->value($row, 'PAYOUT_BANK_ACCOUNT_NUMBER');

        $payment = $this->matchPayment($report->provider, $sourceId, $externalReference);
        $safeRaw = $row;
        foreach (['PAYOUT_BANK_ACCOUNT_NUMBER', 'BANK_ACCOUNT_NUMBER', 'PAYOUT_BANK_ACCOUNT_ID'] as $sensitive) {
            if (array_key_exists($sensitive, $safeRaw)) $safeRaw[$sensitive] = $this->maskReference((string) $safeRaw[$sensitive]);
        }
        if ($payment) $safeRaw['_matched_payment_id'] = $payment->id;

        $fingerprint = hash('sha256', implode('|', [
            $report->provider, $sourceId, $externalReference, $recordType, $description,
            $occurredAt?->toIso8601String() ?? '', $credit, $debit,
            $this->number($balanceRaw),
        ]));

        $entry = ProviderStatementEntry::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'report_id' => $report->id,
                'provider' => $report->provider,
                'provider_source_id' => $sourceId ?: null,
                'external_reference' => $externalReference ?: null,
                'record_type' => $recordType ?: null,
                'description' => $description ?: null,
                'currency' => strtoupper($this->value($row, 'CURRENCY') ?: 'BRL'),
                'gross_amount' => $this->number($this->value($row, 'GROSS_AMOUNT')),
                'net_credit_amount' => $credit,
                'net_debit_amount' => $debit,
                'provider_fee_amount' => $this->number($this->value($row, 'MP_FEE_AMOUNT')),
                'seller_amount' => $this->nullableNumber($this->value($row, 'SELLER_AMOUNT')),
                'balance_amount' => $balanceRaw === '' ? null : $this->number($balanceRaw),
                'occurred_at' => $occurredAt,
                'bank_account_reference' => $this->maskReference($bankRaw),
                'raw' => $safeRaw,
            ]
        );

        if ($payment && $recordType === 'release' && $credit > 0 && ! in_array($description, ['refund', 'chargeback', 'payout'], true)) {
            $this->markPaymentSettled($payment, $entry);
        }

        return $entry;
    }

    private function matchPayment(string $provider, string $sourceId, string $externalReference): ?EcosystemPayment
    {
        return EcosystemPayment::query()
            ->where('provider', $provider)
            ->where(function ($query) use ($sourceId, $externalReference) {
                if ($sourceId !== '') $query->where('provider_payment_id', $sourceId);
                if ($externalReference !== '') {
                    $sourceId !== '' ? $query->orWhere('source_reference', $externalReference) : $query->where('source_reference', $externalReference);
                }
                if ($sourceId === '' && $externalReference === '') $query->whereRaw('1 = 0');
            })
            ->first();
    }

    private function markPaymentSettled(EcosystemPayment $payment, ProviderStatementEntry $entry): void
    {
        if (! in_array(strtolower((string) $payment->status), ['paid', 'approved', 'refunded', 'charged_back'], true)) return;

        $settledAt = $payment->settled_at ?: $entry->occurred_at ?: now();
        $payment->forceFill([
            'settled_at' => $settledAt,
            'settlement_status' => 'settled',
            'settlement_reference' => 'provider-statement:' . $entry->id,
            'settlement_net_amount' => round((float) $entry->net_credit_amount - (float) $entry->net_debit_amount, 2),
        ])->saveQuietly();

        if (Schema::hasTable('financial_ledger_entries')) {
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => 'payment:' . $payment->id . ':funds_settled'],
                [
                    'public_id' => (string) Str::uuid(),
                    'payment_id' => $payment->id,
                    'app_id' => $payment->app_id,
                    'app_slug' => $payment->app_slug,
                    'establishment_id' => $payment->establishment_id,
                    'production_id' => $payment->production_id,
                    'provider' => $payment->provider,
                    'provider_payment_id' => $payment->provider_payment_id,
                    'event_type' => 'funds_settled',
                    'currency' => $payment->currency ?: 'BRL',
                    'gross_amount' => 0,
                    'platform_amount' => 0,
                    'provider_amount' => 0,
                    'seller_amount' => 0,
                    'occurred_at' => $settledAt,
                    'source_type' => 'provider_statement',
                    'source_reference' => (string) $entry->id,
                    'metadata' => [
                        'statement_entry_id' => $entry->id,
                        'net_credit_amount' => (float) $entry->net_credit_amount,
                        'net_debit_amount' => (float) $entry->net_debit_amount,
                        'balance_amount' => $entry->balance_amount !== null ? (float) $entry->balance_amount : null,
                    ],
                ]
            );
        }
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function number(string $value): float
    {
        $value = trim(str_replace(['R$', ' '], '', $value));
        if ($value === '') return 0.0;
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) $value = str_replace(',', '.', str_replace('.', '', $value));
            else $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        return round((float) $value, 2);
    }

    private function nullableNumber(string $value): ?float
    {
        return $value === '' ? null : $this->number($value);
    }

    private function maskReference(string $value): ?string
    {
        $value = preg_replace('/\s+/', '', trim($value)) ?? '';
        if ($value === '') return null;
        return '****' . substr($value, -4);
    }

    private function safeMetadata(array $data): array
    {
        foreach (['access_token', 'token', 'authorization', 'PAYOUT_BANK_ACCOUNT_NUMBER'] as $key) unset($data[$key]);
        return $data;
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('provider_statement_reports') && Schema::hasTable('provider_statement_entries');
    }

    private function emptySnapshot(): array
    {
        return [
            'provider' => null, 'current_balance' => null, 'balance_at' => null,
            'released_net' => 0, 'bank_withdrawals' => 0, 'bank_withdrawal_count' => 0,
            'period_credits' => 0, 'period_debits' => 0, 'settled_payment_count' => 0,
            'unmatched_release_entries' => 0, 'last_import_at' => null,
            'last_report_status' => null, 'report_errors' => 0, 'entry_count' => 0,
        ];
    }
}
