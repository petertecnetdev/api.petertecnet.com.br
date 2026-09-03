<?php

namespace Tests\Unit;

use App\Services\Reporting\AdministrativeReportService;
use Tests\TestCase;

class AdministrativeReportServiceTest extends TestCase
{
    public function test_it_exposes_the_expected_admin_report_catalog(): void
    {
        $service = $this->app->make(AdministrativeReportService::class);
        $keys = collect($service->definitions())->pluck('key')->all();
        $this->assertSame(['overview','activity','financial','applications','users','establishments','items','audit'], $keys);
        $this->assertCount(8, $service->definitions());
        $this->assertSame(['pdf','csv','xlsx'], $service->definitions()[0]['formats']);
    }
}
