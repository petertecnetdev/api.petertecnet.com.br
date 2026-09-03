<?php

namespace App\Services\Reporting\Renderers;

use App\Services\Reporting\Contracts\ReportRenderer;

class CsvReportRenderer implements ReportRenderer
{
    public function format():string{return'csv';}public function extension():string{return'csv';}public function mimeType():string{return'text/csv; charset=UTF-8';}
    public function render(array $report):string
    {
        $stream=fopen('php://temp','r+');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,[$report['title']??'Relatório'],';');fputcsv($stream,['Período',data_get($report,'period.label','Sem limite')],';');fputcsv($stream,['Gerado em',$report['generated_at']??''],';');foreach(($report['summary']??[])as$label=>$value)fputcsv($stream,[$label,$value],';');fputcsv($stream,[],';');$columns=$report['columns']??[];fputcsv($stream,array_map(fn($column)=>$column['label'],$columns),';');foreach(($report['rows']??[])as$row)fputcsv($stream,array_map(fn($column)=>data_get($row,$column['key'],''),$columns),';');rewind($stream);$content=stream_get_contents($stream);fclose($stream);return$content?:'';
    }
}
