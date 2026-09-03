<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentRevenueRecognitionService;
use App\Services\Reporting\AdministrativeReportService;
use PHPUnit\Framework\TestCase;

class AdministrativeReportServiceTest extends TestCase
{
    public function test_it_exposes_the_expected_admin_report_catalog(): void
    {
        $service = new AdministrativeReportService(new PaymentRevenueRecognitionService());
        $keys = collect($service->definitions())->pluck('key')->all();

        $this->assertSame([
            'overview',
            'activity',
            'financial',
            'applications',
            'users',
            'establishments',
            'items',
            'audit',
        ], $keys);
        $this->assertCount(8, $service->definitions());
    }
}
