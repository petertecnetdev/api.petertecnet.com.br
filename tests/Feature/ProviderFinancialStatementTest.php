<?php

namespace Tests\Feature;

use App\Models\EcosystemPayment;
use App\Models\FinancialLedgerEntry;
use App\Models\ProviderStatementEntry;
use App\Models\ProviderStatementReport;
use App\Services\Payments\ProviderFinancialStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderFinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_provider_release_settles_paid_payment_without_counting_sale_twice(): void
    {
        Carbon::setTestNow('2026-09-03 12:00:00');
        config(['services.mercadopago.access_token' => 'test-token']);

        $payment = $this->payment([
            'status' => 'paid',
            'provider_payment_id' => '123456',
            'source_reference' => 'order-abc',
            'paid_at' => now()->subHour(),
        ]);
        $report = $this->report();
        $csv = $this->csv([
            ['2026-09-03T14:00:00Z','123456','order-abc','release','payment','95.00','0.00','90.00','100.00','5.00','245.00','','BRL','true'],
            ['2026-09-03T15:00:00Z','','','release','payout','0.00','30.00','','0.00','0.00','215.00','00123456789','BRL','true'],
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/account/release_report/task/999' => Http::response([
                'id' => 999, 'status' => 'processed', 'report_id' => 77, 'file_name' => 'release.csv',
            ]),
            'https://api.mercadopago.com/v1/account/release_report/release.csv' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
        ]);

        $service = app(ProviderFinancialStatementService::class);
        $this->assertSame(2, $service->syncReport($report));

        $payment->refresh();
        $this->assertSame('settled', $payment->settlement_status);
        $this->assertSame('95.00', $payment->settlement_net_amount);
        $this->assertTrue($payment->settled_at->equalTo(Carbon::parse('2026-09-03T14:00:00Z')));

        $settlementEvent = FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'funds_settled')->firstOrFail();
        $this->assertSame('0.00', $settlementEvent->gross_amount);
        $this->assertSame('0.00', $settlementEvent->platform_amount);

        $this->assertDatabaseCount('provider_statement_entries', 2);
        $payout = ProviderStatementEntry::query()->where('description', 'payout')->firstOrFail();
        $this->assertSame('****6789', $payout->bank_account_reference);
        $this->assertSame('****6789', $payout->raw['PAYOUT_BANK_ACCOUNT_NUMBER']);

        $snapshot = $service->snapshot(null, null, 'mercadopago');
        $this->assertSame(215.0, $snapshot['current_balance']);
        $this->assertSame(30.0, $snapshot['bank_withdrawals']);
        $this->assertSame(1, $snapshot['settled_payment_count']);
        $this->assertSame(0, $snapshot['unmatched_release_entries']);
    }

    public function test_reimport_is_idempotent_and_preserves_first_settlement_timestamp(): void
    {
        config(['services.mercadopago.access_token' => 'test-token']);
        $payment = $this->payment([
            'status' => 'paid', 'provider_payment_id' => '777', 'source_reference' => 'order-777', 'paid_at' => now()->subHour(),
        ]);
        $report = $this->report();
        $csv = $this->csv([
            ['2026-09-03T13:30:00Z','777','order-777','release','payment','48.00','0.00','45.00','50.00','2.00','148.00','','BRL','true'],
        ]);
        Http::fake([
            'https://api.mercadopago.com/v1/account/release_report/task/999' => Http::response(['id'=>999,'status'=>'processed','report_id'=>77,'file_name'=>'release.csv']),
            'https://api.mercadopago.com/v1/account/release_report/release.csv' => Http::response($csv),
        ]);

        $service = app(ProviderFinancialStatementService::class);
        $service->syncReport($report);
        $first = $payment->fresh()->settled_at;

        Carbon::setTestNow('2026-09-04 12:00:00');
        $service->syncReport($report->fresh());

        $this->assertDatabaseCount('provider_statement_entries', 1);
        $this->assertSame(1, FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'funds_settled')->count());
        $this->assertTrue($payment->fresh()->settled_at->equalTo($first));
    }

    public function test_release_report_never_settles_an_open_qr_payment(): void
    {
        config(['services.mercadopago.access_token' => 'test-token']);
        $payment = $this->payment([
            'status' => 'pending', 'provider_payment_id' => '888', 'source_reference' => 'qr-888', 'paid_at' => null,
        ]);
        $report = $this->report();
        $csv = $this->csv([
            ['2026-09-03T13:30:00Z','888','qr-888','release','payment','99.00','0.00','95.00','100.00','1.00','99.00','','BRL','true'],
        ]);
        Http::fake([
            'https://api.mercadopago.com/v1/account/release_report/task/999' => Http::response(['id'=>999,'status'=>'processed','report_id'=>77,'file_name'=>'release.csv']),
            'https://api.mercadopago.com/v1/account/release_report/release.csv' => Http::response($csv),
        ]);

        app(ProviderFinancialStatementService::class)->syncReport($report);

        $payment->refresh();
        $this->assertSame('unverified', $payment->settlement_status);
        $this->assertNull($payment->settled_at);
        $this->assertSame(0, FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'funds_settled')->count());
    }

    public function test_maintenance_completes_existing_report_config_and_requests_only_one_report_per_window(): void
    {
        Carbon::setTestNow('2026-09-03 12:10:00');
        config(['services.mercadopago.access_token' => 'test-token']);
        $nextTask = 900;

        Http::fake(function ($request) use (&$nextTask) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_ends_with($url, '/v1/account/release_report/config')) {
                return Http::response([
                    'file_name_prefix' => 'existing',
                    'columns' => [['key'=>'SOURCE_ID']],
                    'frequency' => ['hour'=>0,'value'=>1,'type'=>'monthly'],
                    'display_timezone' => 'GMT-04',
                    'include_withdrawal_at_end' => false,
                    'check_available_balance' => false,
                ], 200);
            }
            if ($request->method() === 'PUT' && str_ends_with($url, '/v1/account/release_report/config')) {
                return Http::response($request->data(), 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/account/release_report')) {
                return Http::response(['id' => $nextTask++, 'status' => 'pending'], 202);
            }
            if (str_contains($url, '/v1/account/release_report/task/')) {
                return Http::response(['status' => 'processing'], 200);
            }
            return Http::response([], 404);
        });

        $service = app(ProviderFinancialStatementService::class);
        $first = $service->maintain('mercadopago');
        $second = $service->maintain('mercadopago');

        $this->assertSame(2, $first['requested']);
        $this->assertSame(0, $second['requested']);
        $this->assertDatabaseCount('provider_statement_reports', 2);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_ends_with($request->url(), '/v1/account/release_report/config')) return false;
            $keys = collect($request->data('columns', []))->pluck('key');
            return $keys->contains('BALANCE_AMOUNT')
                && $keys->contains('PAYOUT_BANK_ACCOUNT_NUMBER')
                && $request->data('display_timezone') === 'GMT-03'
                && $request->data('include_withdrawal_at_end') === true;
        });
    }

    private function payment(array $overrides = []): EcosystemPayment
    {
        return EcosystemPayment::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'app_id' => null,
            'app_slug' => 'financial-test-app',
            'provider' => 'mercadopago',
            'provider_payment_id' => null,
            'source_type' => 'financial_test',
            'source_reference' => 'test-' . Str::uuid(),
            'source_id' => null,
            'user_id' => null,
            'production_id' => null,
            'establishment_id' => null,
            'currency' => 'BRL',
            'method' => 'pix',
            'status' => 'pending',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'provider_fee' => 1,
            'seller_net' => 89,
            'metadata' => [],
        ], $overrides));
    }

    private function report(): ProviderStatementReport
    {
        return ProviderStatementReport::query()->create([
            'provider' => 'mercadopago',
            'report_type' => 'released_money',
            'window_key' => 'test-' . Str::uuid(),
            'provider_task_id' => '999',
            'provider_report_id' => null,
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    private function csv(array $rows): string
    {
        $headers = ['DATE','SOURCE_ID','EXTERNAL_REFERENCE','RECORD_TYPE','DESCRIPTION','NET_CREDIT_AMOUNT','NET_DEBIT_AMOUNT','SELLER_AMOUNT','GROSS_AMOUNT','MP_FEE_AMOUNT','BALANCE_AMOUNT','PAYOUT_BANK_ACCOUNT_NUMBER','CURRENCY','IS_RELEASED'];
        return implode(';', $headers) . "\n" . collect($rows)->map(fn ($row) => implode(';', $row))->implode("\n") . "\n";
    }
}
