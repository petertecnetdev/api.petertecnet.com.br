<?php

namespace Tests\Unit;

use App\Services\Reporting\Renderers\CsvReportRenderer;
use App\Services\Reporting\Renderers\XlsxReportRenderer;
use Tests\TestCase;

class ReportRenderersTest extends TestCase
{
    private function payload(): array
    {
        return [
            'title' => 'Teste',
            'period' => ['label' => '01/09/2026 a 03/09/2026'],
            'generated_at' => '03/09/2026 10:00:00',
            'summary' => ['Total' => 1],
            'columns' => [['key' => 'name', 'label' => 'Nome']],
            'rows' => [['name' => 'Peter Tecnet']],
        ];
    }

    public function test_csv_contains_utf8_bom_and_rows(): void
    {
        $csv = (new CsvReportRenderer())->render($this->payload());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Peter Tecnet', $csv);
    }

    public function test_xlsx_is_a_valid_ooxml_zip_payload(): void
    {
        $xlsx = (new XlsxReportRenderer())->render($this->payload());
        $this->assertStringStartsWith("PK\x03\x04", $xlsx);
        $this->assertStringContainsString('[Content_Types].xml', $xlsx);
        $this->assertStringContainsString('xl/worksheets/sheet1.xml', $xlsx);
    }
}
