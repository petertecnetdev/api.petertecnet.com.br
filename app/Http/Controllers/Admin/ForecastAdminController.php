<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Forecast;
use App\Services\ForecastEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ForecastAdminController extends Controller
{
    public function __construct(private readonly ForecastEngineService $engine) {}

    public function reports(Request $request)
    {
        $this->admin($request);
        return response()->json(['data'=>DB::table('forecast_reports as r')->join('forecasts as f','f.id','=','r.forecast_id')
            ->leftJoin('users as u','u.id','=','r.user_id')->select('r.*','f.slug','f.statement','f.status as forecast_status','u.user_name')
            ->orderByRaw("r.status = 'open' DESC")->latest('r.id')->paginate(50)]);
    }

    public function resolving(Request $request)
    {
        $this->admin($request);
        return response()->json(['data'=>Forecast::where('status','resolving')->with('user:id,user_name,first_name,last_name')->orderBy('deadline_at')->paginate(50)]);
    }

    public function moderate(Request $request,Forecast $forecast)
    {
        $this->admin($request);
        $d=$request->validate(['moderation_status'=>['required','in:approved,review,hidden'],'visibility'=>['nullable','in:public,unlisted,hidden']]);
        $forecast->update($d);return response()->json(['forecast'=>$forecast->fresh()]);
    }

    public function proposeResolution(Request $request,Forecast $forecast)
    {
        $this->admin($request);$p=$this->engine->proposeResolution($forecast);
        return response()->json(['proposal'=>$p],$p?201:422);
    }

    public function resolve(Request $request,Forecast $forecast)
    {
        $this->admin($request);
        $d=$request->validate(['outcome'=>['required','in:yes,no,indeterminate,invalidated'],'rationale'=>['nullable','string','max:5000'],
            'sources'=>['required','array','min:1','max:20'],'sources.*.url'=>['required','url','max:2048'],'sources.*.title'=>['nullable','string','max:255']]);
        $r=$this->engine->finalize($forecast,$d['outcome'],$d['sources'],$d['rationale']??null,$request->user()?->id);
        return response()->json(['resolution'=>$r,'forecast'=>$forecast->fresh()]);
    }

    public function updateReport(Request $request,int $report)
    {
        $this->admin($request);
        $d=$request->validate(['status'=>['required','in:open,reviewing,resolved,dismissed'],'review_notes'=>['nullable','string','max:3000']]);
        DB::table('forecast_reports')->where('id',$report)->update([...$d,'reviewed_by_user_id'=>$request->user()?->id,'reviewed_at'=>now(),'updated_at'=>now()]);
        return response()->json(['report'=>DB::table('forecast_reports')->where('id',$report)->first()]);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('application_manage'),403,'Usuário sem permissão para moderar previsões.');
    }
}
