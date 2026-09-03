<?php

namespace App\Services\Reporting;

use App\Models\Application;use App\Models\EcosystemAuditLog;use App\Models\Interaction;use App\Models\Item;use App\Models\Profile;

class AdministrativeReportService
{
    public function __construct(private ReportRegistry $registry){}
    public function definitions():array{return $this->registry->definitions();}
    public function build(string $type,array $filters):array{return $this->registry->build($type,$filters);}
    public function metadata():array
    {
        return[
            'reports'=>$this->definitions(),
            'applications'=>Application::query()->orderBy('name')->get(['id','name','slug']),
            'profiles'=>Profile::query()->orderBy('name')->get(['id','name']),
            'activity_types'=>Interaction::query()->select('interaction_type')->distinct()->orderBy('interaction_type')->pluck('interaction_type')->filter()->values(),
            'audit_actions'=>EcosystemAuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->filter()->values(),
            'item_types'=>Item::query()->select('type')->distinct()->orderBy('type')->pluck('type')->filter()->values(),
            'formats'=>[['key'=>'pdf','label'=>'PDF'],['key'=>'csv','label'=>'CSV'],['key'=>'xlsx','label'=>'Excel (XLSX)']],
            'generated_at'=>now()->toIso8601String(),
        ];
    }
}
