<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Contracts\ReportRenderer;
use App\Services\Reporting\Renderers\CsvReportRenderer;
use App\Services\Reporting\Renderers\PdfReportRenderer;
use App\Services\Reporting\Renderers\XlsxReportRenderer;

class ReportRendererRegistry
{
    private array $renderers;

    public function __construct(PdfReportRenderer $pdf, CsvReportRenderer $csv, XlsxReportRenderer $xlsx)
    {
        $this->renderers = collect([$pdf, $csv, $xlsx])
            ->mapWithKeys(fn (ReportRenderer $renderer) => [$renderer->format() => $renderer])
            ->all();
    }

    public function get(string $format): ReportRenderer
    {
        abort_unless(isset($this->renderers[$format]), 422, 'Formato de relatório não suportado.');
        return $this->renderers[$format];
    }

    public function formats(): array
    {
        return array_keys($this->renderers);
    }
}
