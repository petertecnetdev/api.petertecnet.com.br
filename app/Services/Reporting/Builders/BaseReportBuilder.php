<?php

namespace App\Services\Reporting\Builders;

use App\Models\Application;
use App\Models\Profile;
use App\Services\Reporting\Contracts\AdministrativeReportBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class BaseReportBuilder implements AdministrativeReportBuilder
{
    public const MAX_ROWS = 1500;

    protected function report(string $title,string $description,array $filters,array $summary,array $columns,array $rows,?Carbon $from,?Carbon $to,bool $truncated=false,array $comparisons=[],array $visuals=[],?int $rowCount=null): array
    {
        return [
            'key'=>$this->key(),'title'=>$title,'description'=>$description,'summary'=>$summary,'columns'=>$columns,'rows'=>$rows,
            'row_count'=>$rowCount??count($rows),'period'=>['from'=>$from?->format('d/m/Y'),'to'=>$to?->format('d/m/Y'),'label'=>$from&&$to?$from->format('d/m/Y').' a '.$to->format('d/m/Y'):'Sem limite de período'],
            'filters'=>$this->filterLabels($filters),'comparisons'=>$comparisons,'visuals'=>$visuals,'generated_at'=>now()->format('d/m/Y H:i:s'),
            'truncated'=>$truncated,'max_rows'=>$this->rowLimit($filters),
        ];
    }

    protected function period(array $filters,bool $defaultThirtyDays): array
    {
        $from=!empty($filters['from'])?Carbon::parse($filters['from'])->startOfDay():null;
        $to=!empty($filters['to'])?Carbon::parse($filters['to'])->endOfDay():null;
        if($defaultThirtyDays&&!$from&&!$to){$to=now()->endOfDay();$from=now()->subDays(29)->startOfDay();}
        elseif($from&&!$to)$to=now()->endOfDay();
        elseif(!$from&&$to)$from=$to->copy()->subDays(29)->startOfDay();
        return [$from,$to];
    }

    protected function previousPeriod(?Carbon $from,?Carbon $to): array
    {
        if(!$from||!$to)return[null,null];
        $days=max((int)ceil($from->diffInDays($to))+1,1);
        return[$from->copy()->subDays($days),$from->copy()->subSecond()];
    }

    protected function comparison($current,$previous): array
    {
        $current=(float)$current;$previous=(float)$previous;
        $change=$previous==0.0?($current>0?100.0:0.0):(($current-$previous)/abs($previous))*100;
        return['current'=>$current,'previous'=>$previous,'change_percent'=>round($change,1),'direction'=>$change>0?'up':($change<0?'down':'stable')];
    }

    protected function applyCreatedPeriod(Builder $query,?Carbon $from,?Carbon $to): void
    {
        if($from&&$to)$query->whereBetween('created_at',[$from,$to]);elseif($from)$query->where('created_at','>=',$from);elseif($to)$query->where('created_at','<=',$to);
    }

    protected function rowLimit(array $filters): int { return min(max((int)($filters['_row_limit']??self::MAX_ROWS),1),250000); }
    protected function integerFilter(array $filters,string $key): ?int { $v=$filters[$key]??null;return is_numeric($v)&&(int)$v>0?(int)$v:null; }
    protected function textFilter(array $filters,string $key): ?string { $v=trim((string)($filters[$key]??''));return $v!==''?Str::limit($v,150,''):null; }

    protected function filterLabels(array $filters): array
    {
        $labels=['app_id'=>'Aplicação','profile_id'=>'Perfil','user_id'=>'Usuário','establishment_id'=>'Estabelecimento','search'=>'Busca','status'=>'Status','type'=>'Tipo','outcome'=>'Resultado','provider'=>'Gateway','method'=>'Método','city'=>'Cidade','uf'=>'UF','action'=>'Ação'];
        return collect($filters)->reject(fn($value,$key)=>str_starts_with((string)$key,'_')||in_array($key,['from','to'],true)||$value===null||$value==='')->mapWithKeys(function($value,$key)use($labels){
            if($key==='app_id')$value=Application::query()->whereKey($value)->value('name')?:$value;
            if($key==='profile_id')$value=Profile::query()->whereKey($value)->value('name')?:$value;
            return[$labels[$key]??$this->humanize($key)=>$this->humanize((string)$value)];
        })->all();
    }

    protected function dateTime($value): string { return $value?Carbon::parse($value)->format('d/m/Y H:i:s'):'—'; }
    protected function date($value): string { return $value?Carbon::parse($value)->format('d/m/Y'):'—'; }
    protected function money($value): string { return 'R$ '.number_format((float)($value??0),2,',','.'); }
    protected function userName($user): string { return trim(collect([$user->first_name??null,$user->last_name??null])->filter()->join(' '))?:($user->user_name??'Usuário'); }
    protected function humanize(string $value): string { return $value==='—'?$value:Str::of($value)->replace(['_','.'],' ')->lower()->title()->toString(); }
    protected function topVisual(iterable $pairs,string $title,int $limit=8): array
    {
        $rows=collect($pairs)->map(fn($value,$label)=>is_array($value)?$value:['label'=>(string)$label,'value'=>(float)$value])->sortByDesc('value')->take($limit)->values();
        return['title'=>$title,'items'=>$rows->all()];
    }
}
