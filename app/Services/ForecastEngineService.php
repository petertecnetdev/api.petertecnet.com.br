<?php

namespace App\Services;

use App\Models\Forecast;
use App\Models\UserReputation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ForecastEngineService
{
    public function aggregate(Forecast $forecast): Forecast
    {
        $latest = DB::table('forecast_estimates')
            ->where('forecast_id',$forecast->id)
            ->selectRaw('MAX(id) id')
            ->groupBy('user_id');

        $rows = DB::table('forecast_estimates')->whereIn('id',$latest)->get();

        if ($rows->isEmpty()) {
            $forecast->update(['participant_count'=>0,'community_probability'=>null,'platform_probability'=>null,'platform_confidence'=>0]);
            return $forecast->fresh();
        }

        $sum = 0.0; $weights = 0.0;
        foreach ($rows as $row) {
            $rep = UserReputation::query()
                ->where('app_id',$forecast->app_id)
                ->where('user_id',$row->user_id)
                ->whereIn('category',[$forecast->category,'all'])
                ->orderByRaw('category = ? DESC',[$forecast->category])
                ->first();
            $score=(float)($rep?->score ?? 50);
            $confidence=(float)($rep?->confidence ?? 0);
            $w=(0.75+($score/100)*0.5)*(0.65+($confidence/100)*0.35);
            $sum += ((float)$row->probability)*$w;
            $weights += $w;
        }

        $community=round($sum/max($weights,0.0001),2);
        $count=$rows->count();
        $forecast->update([
            'participant_count'=>$count,
            'community_probability'=>$community,
            'platform_probability'=>$count>=5 ? $community : null,
            'platform_confidence'=>$count>=5 ? round(min(95,20+(log($count+1,2)*12)),2) : 0,
        ]);
        return $forecast->fresh();
    }

    public function markDue(int $limit=100): array
    {
        $ids=Forecast::query()->where('status','open')->where('deadline_at','<=',now())
            ->orderBy('deadline_at')->limit(max(1,min($limit,500)))->pluck('id');
        Forecast::query()->whereIn('id',$ids)->update(['status'=>'resolving']);
        return ['moved_to_resolving'=>$ids->count(),'forecast_ids'=>$ids->values()->all()];
    }

    public function proposeResolution(Forecast $forecast): ?object
    {
        $key=(string)config('services.openai.api_key');
        if ($key==='' || !in_array($forecast->status,['resolving','open'],true)) return null;

        $prompt="Verifique esta previsão usando fontes públicas confiáveis e datadas. Retorne SOMENTE JSON "
            ."com outcome yes|no|indeterminate|invalidated, confidence 0-100, rationale, sources [{title,url,published_at}]. "
            ."Não trate ambiguidade como certeza.\nPrevisão: {$forecast->statement}\nPrazo: {$forecast->deadline_at?->toIso8601String()}\nCritério: {$forecast->resolution_criteria}";

        try {
            $r=Http::withToken($key)->timeout((int)config('services.openai.timeout',45))
                ->post(rtrim((string)config('services.openai.base_url'),'/').'/responses',[
                    'model'=>(string)config('services.openai.text_model'),
                    'tools'=>[['type'=>'web_search']],
                    'input'=>$prompt,
                ]);
            if(!$r->successful()) return null;
            $payload=$r->json();
            $text=(string)data_get($payload,'output_text','');
            if($text===''){
                foreach((array)data_get($payload,'output',[]) as $item){
                    foreach((array)($item['content']??[]) as $content){
                        if(isset($content['text'])) $text.=$content['text'];
                    }
                }
            }
            $d=json_decode($text,true);
            if(!is_array($d)) return null;
            $outcome=in_array($d['outcome']??'', ['yes','no','indeterminate','invalidated'],true)?$d['outcome']:'indeterminate';
            $id=DB::table('forecast_resolutions')->insertGetId([
                'forecast_id'=>$forecast->id,'outcome'=>$outcome,
                'confidence'=>isset($d['confidence'])?max(0,min(100,(float)$d['confidence'])):null,
                'rationale'=>strip_tags((string)($d['rationale']??'')),
                'sources'=>json_encode(array_slice((array)($d['sources']??[]),0,10),JSON_UNESCAPED_UNICODE),
                'proposed_by'=>'ai','model_version'=>(string)config('services.openai.text_model'),
                'is_final'=>false,'created_at'=>now(),'updated_at'=>now(),
            ]);
            return DB::table('forecast_resolutions')->where('id',$id)->first();
        } catch(\Throwable) { return null; }
    }

    public function finalize(Forecast $forecast,string $outcome,array $sources,?string $rationale,?int $userId): object
    {
        if(!in_array($outcome,['yes','no','indeterminate','invalidated'],true)) throw new \InvalidArgumentException('Invalid outcome');

        return DB::transaction(function() use($forecast,$outcome,$sources,$rationale,$userId){
            DB::table('forecast_resolutions')->where('forecast_id',$forecast->id)->where('is_final',true)->update(['is_final'=>false,'updated_at'=>now()]);
            $id=DB::table('forecast_resolutions')->insertGetId([
                'forecast_id'=>$forecast->id,'outcome'=>$outcome,'confidence'=>100,'rationale'=>$rationale,
                'sources'=>json_encode(array_slice($sources,0,20),JSON_UNESCAPED_UNICODE),'proposed_by'=>'admin',
                'resolved_by_user_id'=>$userId,'is_final'=>true,'resolved_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $forecast->update(['status'=>$outcome==='invalidated'?'invalidated':'resolved','resolved_at'=>now()]);
            if(in_array($outcome,['yes','no'],true)) $this->recalculateReputation($forecast->fresh(),$outcome);
            return DB::table('forecast_resolutions')->where('id',$id)->first();
        });
    }

    private function recalculateReputation(Forecast $forecast,string $outcome): void
    {
        $latestIds=DB::table('forecast_estimates')->where('forecast_id',$forecast->id)
            ->selectRaw('MAX(id) id')->groupBy('user_id')->pluck('id');
        $userIds=DB::table('forecast_estimates')->whereIn('id',$latestIds)->pluck('user_id')->unique();

        foreach($userIds as $userId){
            $this->recalculateUser($forecast->app_id,(int)$userId,'all');
            $this->recalculateUser($forecast->app_id,(int)$userId,$forecast->category);
        }
    }

    public function recalculateUser(int $appId,int $userId,string $category='all'): UserReputation
    {
        $all=DB::table('forecast_estimates as e')
            ->join('forecasts as f','f.id','=','e.forecast_id')
            ->join('forecast_resolutions as r',function($j){
                $j->on('r.forecast_id','=','f.id')->where('r.is_final',true)->whereIn('r.outcome',['yes','no']);
            })
            ->where('f.app_id',$appId)->where('e.user_id',$userId)
            ->when($category!=='all',fn($q)=>$q->where('f.category',$category))
            ->select('e.id','e.forecast_id','e.probability','e.created_at as estimated_at','f.deadline_at','r.outcome')
            ->orderBy('e.id')->get()->groupBy('forecast_id')->map(fn($g)=>$g->last())->values();

        $n=$all->count();
        if($n===0){
            return UserReputation::updateOrCreate(['app_id'=>$appId,'user_id'=>$userId,'category'=>$category],
                ['score'=>50,'calibration_score'=>50,'confidence'=>0,'resolved_count'=>0,'correct_count'=>0,'last_calculated_at'=>now()]);
        }

        $brier=0.0;$correct=0;$lead=0.0;
        foreach($all as $row){
            $p=max(0,min(1,((float)$row->probability)/100));$y=$row->outcome==='yes'?1.0:0.0;
            $brier+=($p-$y)**2;$correct+=(($p>=0.5)===($y===1.0))?1:0;
            $lead+=max(0,\Carbon\Carbon::parse($row->estimated_at)->diffInHours(\Carbon\Carbon::parse($row->deadline_at),false)/24);
        }
        $brier/=$n;$raw=100*(1-$brier);$confidence=100*(1-exp(-$n/25));$score=50+(($raw-50)*($confidence/100));

        return UserReputation::updateOrCreate(['app_id'=>$appId,'user_id'=>$userId,'category'=>$category],[
            'score'=>round(max(0,min(100,$score)),2),'calibration_score'=>round(max(0,min(100,$raw)),2),
            'brier_score'=>round($brier,6),'confidence'=>round($confidence,2),'resolved_count'=>$n,
            'correct_count'=>$correct,'average_lead_days'=>round($lead/$n,2),'last_calculated_at'=>now(),
        ]);
    }
}
