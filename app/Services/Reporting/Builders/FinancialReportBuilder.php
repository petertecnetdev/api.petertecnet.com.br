<?php

namespace App\Services\Reporting\Builders;

use App\Services\Payments\PaymentRevenueRecognitionService;use Carbon\Carbon;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;

class FinancialReportBuilder extends BaseReportBuilder
{
    public function __construct(private PaymentRevenueRecognitionService $recognition){} public function key():string{return'financial';} public function label():string{return'Financeiro';} public function description():string{return'Pagamentos da camada financeira genérica, caixa confirmado e valores em aberto.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,true);[$pf,$pt]=$this->previousPeriod($from,$to);if(!Schema::hasTable('ecosystem_payments'))return $this->emptyReport($filters,$from,$to);
        $raw=$this->rows($filters,$from,$to);$realized=$this->recognition->realized($raw);$failed=$this->recognition->failed($raw);$reversed=$this->recognition->reversed($raw);$totals=$this->recognition->totals($raw);$previousTotals=$this->recognition->totals($this->rows(array_diff_key($filters,['from'=>true,'to'=>true]),$pf,$pt));
        $rows=$raw->take($this->rowLimit($filters))->map(fn($row)=>['date'=>$this->dateTime($row['financial_at']??$row['created_at']??null),'application'=>$row['application_name']??$row['app_slug']??$row['application_slug']??'—','reference'=>$row['public_id']??$row['source_reference']??('#'.($row['id']??'—')),'provider'=>$row['provider']??'—','method'=>$this->humanize($row['method']??'—'),'status'=>$this->humanize($row['status']??'—'),'gross'=>$this->money($row['gross_amount']??0),'platform_fee'=>$this->money($row['platform_fee']??0),'seller_net'=>$this->money($row['seller_net']??0)])->all();
        $methods=$realized->groupBy(fn($row)=>$row['method']??'não informado')->map(fn($g)=>round((float)$g->sum('gross_amount'),2));
        return $this->report('Relatório financeiro','Conciliação baseada exclusivamente na camada financeira genérica ecosystem_payments.',$filters,['Transações'=>$raw->count(),'Pagamentos confirmados'=>$realized->count(),'Caixa confirmado'=>$this->money($totals->gross),'Receita Peter Tecnet'=>$this->money($totals->platform_fees),'Em aberto (não é caixa)'=>$this->money($totals->open_gross),'Falhas / cancelados'=>$failed->count(),'Estornos / chargebacks'=>$reversed->count()],[['key'=>'date','label'=>'Data financeira'],['key'=>'application','label'=>'Aplicação'],['key'=>'reference','label'=>'Referência'],['key'=>'provider','label'=>'Gateway'],['key'=>'method','label'=>'Método'],['key'=>'status','label'=>'Status'],['key'=>'gross','label'=>'Valor bruto'],['key'=>'platform_fee','label'=>'Receita Peter'],['key'=>'seller_net','label'=>'Líquido']],$rows,$from,$to,$raw->count()>$this->rowLimit($filters),['Caixa vs. período anterior'=>$this->comparison($totals->gross,$previousTotals->gross),'Receita Peter vs. período anterior'=>$this->comparison($totals->platform_fees,$previousTotals->platform_fees)],[$this->topVisual($methods,'Volume confirmado por método')],$raw->count());
    }
    private function rows(array $filters,Carbon $from,Carbon $to)
    {
        $columns=collect(['created_at','paid_at','refunded_at','failed_at'])->filter(fn($c)=>Schema::hasColumn('ecosystem_payments',$c))->values();$query=DB::table('ecosystem_payments as p')->leftJoin('applications as a','a.id','=','p.app_id')->select('p.*','a.name as application_name','a.slug as application_slug');
        if($columns->isNotEmpty())$query->where(function($q)use($columns,$from,$to){foreach($columns as $i=>$column){$m=$i===0?'whereBetween':'orWhereBetween';$q->{$m}("p.{$column}",[$from,$to]);}});
        if($appId=$this->integerFilter($filters,'app_id'))$query->where('p.app_id',$appId);if($status=$this->textFilter($filters,'status'))$query->where('p.status',$status);if($method=$this->textFilter($filters,'method'))$query->where('p.method',$method);if($provider=$this->textFilter($filters,'provider'))$query->where('p.provider',$provider);
        return $query->orderByDesc('p.created_at')->limit(max($this->rowLimit($filters)+1,10000))->get()->map(fn($r)=>$this->recognition->normalize(json_decode(json_encode($r),true)))->filter(function($row)use($from,$to){$timestamp=$row['financial_at']??$row['created_at']??null;return $timestamp&&Carbon::parse($timestamp)->betweenIncluded($from,$to);})->values();
    }
    private function emptyReport(array $filters,Carbon $from,Carbon $to):array{return $this->report('Relatório financeiro','A camada financeira genérica ainda não possui registros disponíveis para relatório.',$filters,['Transações'=>0,'Caixa confirmado'=>$this->money(0),'Em aberto'=>$this->money(0)],[['key'=>'date','label'=>'Data'],['key'=>'application','label'=>'Aplicação'],['key'=>'status','label'=>'Status'],['key'=>'method','label'=>'Método'],['key'=>'gross','label'=>'Valor']],[],$from,$to);}
}
