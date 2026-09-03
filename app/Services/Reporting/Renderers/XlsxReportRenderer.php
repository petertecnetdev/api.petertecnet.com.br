<?php

namespace App\Services\Reporting\Renderers;

use App\Services\Reporting\Contracts\ReportRenderer;

class XlsxReportRenderer implements ReportRenderer
{
    public function format():string{return'xlsx';}public function extension():string{return'xlsx';}public function mimeType():string{return'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';}
    public function render(array $report):string
    {
        $rows=[[$report['title']??'Relatório'],['Período',data_get($report,'period.label','Sem limite')],['Gerado em',$report['generated_at']??'']];foreach(($report['summary']??[])as$label=>$value)$rows[]=[$label,$value];$rows[]=[];$columns=$report['columns']??[];$rows[]=array_map(fn($c)=>$c['label'],$columns);foreach(($report['rows']??[])as$row)$rows[]=array_map(fn($c)=>data_get($row,$c['key'],''),$columns);
        $sheetRows=[];foreach($rows as$rIndex=>$row){$cells=[];foreach(array_values($row)as$cIndex=>$value){$ref=$this->columnName($cIndex+1).($rIndex+1);$text=htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8');$cells[]='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';}$sheetRows[]='<row r="'.($rIndex+1).'">'.implode('',$cells).'</row>';}
        $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('',$sheetRows).'</sheetData></worksheet>';
        return $this->zip([
            '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Relatório" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>$sheet,
        ]);
    }
    private function columnName(int $index):string{$name='';while($index>0){$index--;$name=chr(65+($index%26)).$name;$index=intdiv($index,26);}return$name;}
    private function zip(array $files):string
    {
        $data='';$central='';$offset=0;foreach($files as$name=>$contents){$name=str_replace('\\','/',$name);$crc=crc32($contents);$size=strlen($contents);$nameLength=strlen($name);$local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,0,$crc,$size,$size,$nameLength,0).$name.$contents;$data.=$local;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,0,$crc,$size,$size,$nameLength,0,0,0,0,0,$offset).$name;$offset+=strlen($local);} $count=count($files);return$data.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($data),0);
    }
}
