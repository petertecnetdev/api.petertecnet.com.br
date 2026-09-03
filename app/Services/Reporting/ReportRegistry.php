<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Builders\ActivityReportBuilder;use App\Services\Reporting\Builders\ApplicationsReportBuilder;use App\Services\Reporting\Builders\AuditReportBuilder;use App\Services\Reporting\Builders\EstablishmentsReportBuilder;use App\Services\Reporting\Builders\FinancialReportBuilder;use App\Services\Reporting\Builders\ItemsReportBuilder;use App\Services\Reporting\Builders\OverviewReportBuilder;use App\Services\Reporting\Builders\UsersReportBuilder;use App\Services\Reporting\Contracts\AdministrativeReportBuilder;

class ReportRegistry
{
    private array $builders;
    public function __construct(OverviewReportBuilder $overview,ActivityReportBuilder $activity,FinancialReportBuilder $financial,ApplicationsReportBuilder $applications,UsersReportBuilder $users,EstablishmentsReportBuilder $establishments,ItemsReportBuilder $items,AuditReportBuilder $audit)
    {
        $this->builders=collect([$overview,$activity,$financial,$applications,$users,$establishments,$items,$audit])->mapWithKeys(fn(AdministrativeReportBuilder $builder)=>[$builder->key()=>$builder])->all();
    }
    public function definitions():array{return collect($this->builders)->map(fn(AdministrativeReportBuilder $builder)=>['key'=>$builder->key(),'label'=>$builder->label(),'description'=>$builder->description(),'formats'=>['pdf','csv','xlsx']])->values()->all();}
    public function has(string $key):bool{return isset($this->builders[$key]);}
    public function get(string $key):AdministrativeReportBuilder{abort_unless($this->has($key),404,'Tipo de relatório não encontrado.');return $this->builders[$key];}
    public function build(string $key,array $filters):array{return $this->get($key)->build($filters);}
}
