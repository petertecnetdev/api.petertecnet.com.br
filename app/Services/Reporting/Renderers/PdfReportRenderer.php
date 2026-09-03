<?php

namespace App\Services\Reporting\Renderers;

use App\Services\Reporting\Contracts\ReportRenderer;use Barryvdh\DomPDF\Facade\Pdf;

class PdfReportRenderer implements ReportRenderer
{
    public function format():string{return'pdf';}public function extension():string{return'pdf';}public function mimeType():string{return'application/pdf';}
    public function render(array $report):string{return Pdf::loadView('reports.administrative',['report'=>$report])->setPaper('a4','landscape')->output();}
}
