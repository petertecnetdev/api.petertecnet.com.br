<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ModerationController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function reports(Request $request)
    {
        $this->requireModerator($request);$appId=$this->context->id();$data=$request->validate(['status'=>'nullable|in:open,reviewing,resolved,dismissed','reason'=>'nullable|string|max:40','q'=>'nullable|string|max:120','per_page'=>'nullable|integer|min:10|max:100']);
        $query=DB::table('event_reports as r')->join('events as e','e.id','=','r.event_id')->join('users as u','u.id','=','r.user_id')->leftJoin('users as reviewer','reviewer.id','=','r.reviewed_by')->where('r.app_id',$appId)->where('e.app_id',$appId)->select(['r.id','r.event_id','r.user_id','r.reason','r.details','r.status','r.moderation_note','r.reviewed_by','r.reviewed_at','r.created_at','r.updated_at','e.title as event_title','e.slug as event_slug','u.first_name as reporter_first_name','u.last_name as reporter_last_name','u.email as reporter_email','reviewer.first_name as reviewer_first_name','reviewer.last_name as reviewer_last_name']);
        if(!empty($data['status']))$query->where('r.status',$data['status']);if(!empty($data['reason']))$query->where('r.reason',$data['reason']);if($term=trim((string)($data['q']??'')))$query->where(fn($q)=>$q->where('e.title','like',"%{$term}%")->orWhere('r.details','like',"%{$term}%")->orWhere('u.first_name','like',"%{$term}%")->orWhere('u.last_name','like',"%{$term}%")->orWhere('u.email','like',"%{$term}%"));
        return response()->json(['reports'=>$query->orderByRaw("CASE r.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")->orderByDesc('r.created_at')->paginate((int)($data['per_page']??25)),'counts'=>['open'=>DB::table('event_reports')->where(['app_id'=>$appId,'status'=>'open'])->count(),'reviewing'=>DB::table('event_reports')->where(['app_id'=>$appId,'status'=>'reviewing'])->count(),'resolved'=>DB::table('event_reports')->where(['app_id'=>$appId,'status'=>'resolved'])->count(),'dismissed'=>DB::table('event_reports')->where(['app_id'=>$appId,'status'=>'dismissed'])->count()]]);
    }

    public function updateReport(Request $request,int $reportId)
    {
        $this->requireModerator($request);$appId=$this->context->id();$data=$request->validate(['status'=>'required|in:open,reviewing,resolved,dismissed','moderation_note'=>'nullable|string|max:5000']);$report=DB::table('event_reports')->where('app_id',$appId)->where('id',$reportId)->first();abort_unless($report,404,'Denúncia não encontrada neste contexto.');
        DB::table('event_reports')->where('app_id',$appId)->where('id',$reportId)->update(['status'=>$data['status'],'moderation_note'=>$data['moderation_note']??null,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Denúncia atualizada com sucesso.','report'=>DB::table('event_reports')->where('app_id',$appId)->where('id',$reportId)->first()]);
    }

    private function requireModerator(Request $request):void{abort_unless($request->user()&&$request->user()->hasProfile('Administrador'),403,'Apenas a moderação da aplicação pode acessar denúncias.');}
}
