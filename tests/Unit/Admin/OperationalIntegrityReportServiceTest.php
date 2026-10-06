<?php

namespace Tests\Unit\Admin;

use App\Models\Application;
use App\Services\Admin\OperationalIntegrityReportService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalIntegrityReportServiceTest extends TestCase
{
    public function test_report_is_read_only_and_clamps_window(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(false);

        $application = new Application([
            'id' => 42,
            'name' => 'Example App',
            'slug' => 'example-app',
        ]);

        $report = app(OperationalIntegrityReportService::class)->report($application, 999);

        $this->assertTrue($report['read_only']);
        $this->assertSame(168, $report['window']['hours']);
        $this->assertSame(0, $report['summary']['pending_payments']);
        $this->assertSame(0, $report['summary']['failed_payments']);
        $this->assertSame(0, $report['summary']['unreconciled_payments']);
        $this->assertSame(0, $report['summary']['expired_pending_orders']);
        $this->assertSame(0, $report['summary']['open_high_severity_issues']);
        $this->assertTrue($report['checks']['report_does_not_mutate_data']);
    }
}
