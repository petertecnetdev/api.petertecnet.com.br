<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Forecast;
use App\Models\Interaction;
use App\Models\User;
use App\Models\UserReputation;
use App\Services\ForecastAnalysisService;
use App\Services\ForecastEngineService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastAnalysisService $analysis,
        private readonly ForecastEngineService $engine,
    ) {}

    public function health()
    {
        return response()->json(['status'=>'ok','service'=>'forecasting','time'=>now()->toIso8601String()]);
    }

    public function index(Request $request)
    {
        $app=$this->app($request);
        $q=Forecast::query()->publicVisible()->where('app_id',$app->id)->whereNotIn('status',['draft','cancelled'])
            ->with('user:id,user_name,first_name,last_name,avatar');

        if($request->filled('status')) $q->where('status',$request->string('status')->toString());
        if($request->filled('category')) $q->where('category',$request->string('category')->toString());
        if($request->filled('q')){
            $term=trim($request->string('q')->toString());
            $q->where(fn($x)=>$x->where('statement','like',"%{$term}%")->orWhere('summary','like',"%{$term}%")->orWhere('category','like',"%{$term}%"));
        }

        match($request->string('sort','recent')->toString()){
            'deadline'=>$q->orderBy('deadline_at'),
            'probability'=>$q->orderByDesc('platform_probability'),
            'trending'=>$q->orderByDesc(DB::raw('(participant_count * 3) + (comment_count * 2) + follow_count')),
            default=>$q->latest('id'),
        };

        $page=$q->paginate(min(max((int)$request->input('per_page',20),1),50));
        return response()->json([
            'data'=>collect($page->items())->map(fn(Forecast $f)=>$this->serialize($f,$request->user())),
            'meta'=>['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total()],
        ]);
    }

    public function show(Request $request,string $slug)
    {
        $f=Forecast::query()->publicVisible()->where('slug',$slug)->with('user:id,user_name,first_name,last_name,avatar')->firstOrFail();
        Interaction::registerView($f,$request->user());
        return response()->json(['forecast'=>$this->serialize($f,$request->user(),true)]);
    }

    public function analyze(Request $request)
    {
        $d=$request->validate(['text'=>['required','string','min:15','max:1200']]);
        return response()->json(['analysis'=>$this->analysis->analyze($d['text'])]);
    }

    public function store(Request $request)
    {
        $d=$request->validate([
            'text'=>['required','string','min:15','max:1200'],
            'deadline_at'=>['nullable','date','after:now'],
            'author_probability'=>['nullable','numeric','between:0,100'],
            'category'=>['nullable','string','max:100'],
            'resolution_criteria'=>['nullable','string','max:2000'],
        ]);
        $app=$this->app($request);$a=$this->analysis->analyze($d['text']);
        if(($a['moderation']['status']??'approved')==='review') return response()->json(['message'=>'Esta previsão precisa de revisão antes da publicação.','analysis'=>$a],422);

        $deadline=$d['deadline_at']??($a['deadline_at']??null);
        $criteria=trim((string)($d['resolution_criteria']??($a['resolution_criteria']??'')));
        if(!$deadline || mb_strlen($criteria)<12) return response()->json(['message'=>'Defina um prazo e um critério objetivo de resolução.','analysis'=>$a],422);

        $f=DB::transaction(function() use($request,$d,$a,$app,$deadline,$criteria){
            $statement=trim((string)($a['statement']?:$d['text']));
            $base=Str::slug(Str::limit($statement,80,''))?:'previsao';$slug=$base;$i=2;
            while(Forecast::withTrashed()->where('slug',$slug)->exists()) $slug=$base.'-'.$i++;
            $deadlineAt=Carbon::parse($deadline);$locked=$deadlineAt->copy()->subHour();if($locked->isPast())$locked=now();

            $f=Forecast::create([
                'app_id'=>$app->id,'user_id'=>$request->user()->id,'slug'=>$slug,
                'original_statement'=>trim(strip_tags($d['text'])),'statement'=>$statement,'summary'=>$a['summary']??null,
                'category'=>$d['category']??($a['category']??'Geral'),'topics'=>$a['topics']??[],'entities'=>$a['entities']??[],
                'deadline_at'=>$deadlineAt,'locked_at'=>$locked,'status'=>'open','visibility'=>'public',
                'author_probability'=>$d['author_probability']??($a['suggested_author_probability']??null),
                'resolution_criteria'=>$criteria,'resolution_source_requirements'=>$a['source_requirements']??[],
                'analysis'=>$a,'model_version'=>$a['model_version']??null,'moderation_status'=>'approved',
            ]);

            DB::table('forecast_versions')->insert([
                'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'version_no'=>1,'statement'=>$f->statement,
                'author_probability'=>$f->author_probability,'deadline_at'=>$f->deadline_at,'resolution_criteria'=>$f->resolution_criteria,
                'snapshot'=>json_encode($f->only(['original_statement','statement','summary','category','topics','entities','analysis']),JSON_UNESCAPED_UNICODE),
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            if($f->author_probability!==null) DB::table('forecast_estimates')->insert([
                'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'probability'=>$f->author_probability,
                'rationale'=>'Estimativa inicial do autor','revision'=>1,'created_at'=>now(),'updated_at'=>now(),
            ]);
            return $f;
        });

        $f=$this->engine->aggregate($f);
        Interaction::register('create',$f,$request->user(),['source_channel'=>'prevora'],'Criação de previsão');
        return response()->json(['forecast'=>$this->serialize($f->load('user'),$request->user(),true)],201);
    }

    public function update(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();
        abort_unless($f->user_id===$request->user()->id,403);
        abort_unless(in_array($f->status,['draft','open'],true),409,'Previsão não pode mais ser editada.');
        abort_if($f->locked_at && $f->locked_at->isPast(),409,'Previsão já está bloqueada.');

        $d=$request->validate([
            'statement'=>['nullable','string','min:15','max:1200'],'author_probability'=>['nullable','numeric','between:0,100'],
            'deadline_at'=>['nullable','date','after:now'],'resolution_criteria'=>['nullable','string','min:12','max:2000'],
        ]);
        if(isset($d['statement'])){
            $m=$this->analysis->moderate($d['statement']);abort_if($m['status']==='review',422,'Conteúdo exige revisão.');
            $d['statement']=trim(strip_tags($d['statement']));
        }

        DB::transaction(function() use($f,$d,$request){
            $f->fill($d)->save();
            $v=(int)DB::table('forecast_versions')->where('forecast_id',$f->id)->max('version_no')+1;
            DB::table('forecast_versions')->insert([
                'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'version_no'=>$v,'statement'=>$f->statement,
                'author_probability'=>$f->author_probability,'deadline_at'=>$f->deadline_at,'resolution_criteria'=>$f->resolution_criteria,
                'snapshot'=>json_encode($f->only(['statement','author_probability','deadline_at','resolution_criteria']),JSON_UNESCAPED_UNICODE),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        Interaction::registerUpdate($f,$request->user(),$d,['source_channel'=>'prevora']);
        return response()->json(['forecast'=>$this->serialize($f->fresh()->load('user'),$request->user(),true)]);
    }

    public function destroy(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();abort_unless($f->user_id===$request->user()->id,403);
        abort_unless($f->status==='draft'||$f->participant_count===0,409,'Previsão com participação não pode ser apagada.');
        $f->delete();Interaction::register('delete',$f,$request->user(),['source_channel'=>'prevora'],'Exclusão de previsão');
        return response()->json(null,204);
    }

    public function estimate(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();abort_unless($f->canEstimate(),409,'Esta previsão não aceita mais estimativas.');
        $d=$request->validate(['probability'=>['required','numeric','between:0,100'],'rationale'=>['nullable','string','max:1500']]);
        $revision=(int)DB::table('forecast_estimates')->where('forecast_id',$f->id)->where('user_id',$request->user()->id)->max('revision')+1;
        DB::table('forecast_estimates')->insert([
            'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'probability'=>round((float)$d['probability'],2),
            'rationale'=>isset($d['rationale'])?trim(strip_tags($d['rationale'])):null,'revision'=>$revision,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $f=$this->engine->aggregate($f);
        Interaction::register('forecast_estimate',$f,$request->user(),['probability'=>(float)$d['probability'],'revision'=>$revision],'Estimativa');
        return response()->json(['forecast'=>$this->serialize($f->load('user'),$request->user(),true)]);
    }

    public function follow(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();
        $existing=DB::table('forecast_follows')->where('forecast_id',$f->id)->where('user_id',$request->user()->id)->first();
        if($existing){DB::table('forecast_follows')->where('id',$existing->id)->delete();$following=false;}
        else{DB::table('forecast_follows')->insert(['forecast_id'=>$f->id,'user_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);$following=true;}
        $count=DB::table('forecast_follows')->where('forecast_id',$f->id)->count();$f->update(['follow_count'=>$count]);
        return response()->json(['following'=>$following,'follow_count'=>$count]);
    }

    public function evidence(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();
        $d=$request->validate(['stance'=>['required','in:support,against,context'],'title'=>['required','string','max:255'],
            'url'=>['required','url','max:2048'],'source_name'=>['nullable','string','max:255'],'published_at'=>['nullable','date','before_or_equal:now'],
            'excerpt'=>['nullable','string','max:1000']]);
        $id=DB::table('forecast_evidence')->insertGetId([
            'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'stance'=>$d['stance'],'title'=>trim(strip_tags($d['title'])),
            'url'=>$d['url'],'source_name'=>$d['source_name']??null,'published_at'=>$d['published_at']??null,
            'excerpt'=>isset($d['excerpt'])?trim(strip_tags($d['excerpt'])):null,'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $f->update(['evidence_count'=>DB::table('forecast_evidence')->where('forecast_id',$f->id)->where('status','active')->count()]);
        Interaction::register('forecast_evidence',$f,$request->user(),['evidence_id'=>$id]);
        return response()->json(['evidence'=>DB::table('forecast_evidence')->where('id',$id)->first()],201);
    }

    public function comment(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();
        $d=$request->validate(['body'=>['required','string','max:2500'],'parent_id'=>['nullable','integer','exists:forecast_comments,id']]);
        $body=trim(strip_tags($d['body']));$m=$this->analysis->moderate($body);
        $id=DB::table('forecast_comments')->insertGetId([
            'forecast_id'=>$f->id,'user_id'=>$request->user()->id,'parent_id'=>$d['parent_id']??null,'body'=>$body,
            'moderation_status'=>$m['status']==='review'?'review':'approved','created_at'=>now(),'updated_at'=>now(),
        ]);
        $f->update(['comment_count'=>DB::table('forecast_comments')->where('forecast_id',$f->id)->where('moderation_status','approved')->whereNull('deleted_at')->count()]);
        Interaction::registerComment($f,$request->user(),$body);
        return response()->json(['comment'=>DB::table('forecast_comments')->where('id',$id)->first()],201);
    }

    public function report(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();
        $d=$request->validate(['reason'=>['required','in:spam,misinformation,harassment,privacy,illegal,other'],'details'=>['nullable','string','max:2000']]);
        $id=DB::table('forecast_reports')->insertGetId(['forecast_id'=>$f->id,'user_id'=>$request->user()->id,'reason'=>$d['reason'],
            'details'=>isset($d['details'])?trim(strip_tags($d['details'])):null,'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['report'=>DB::table('forecast_reports')->where('id',$id)->first()],201);
    }

    public function dispute(Request $request,string $slug)
    {
        $f=Forecast::where('slug',$slug)->firstOrFail();abort_unless(in_array($f->status,['resolved','invalidated'],true),409);
        $d=$request->validate(['reason'=>['required','string','min:20','max:3000']]);
        $id=DB::table('forecast_disputes')->insertGetId(['forecast_id'=>$f->id,'user_id'=>$request->user()->id,
            'reason'=>trim(strip_tags($d['reason'])),'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['dispute'=>DB::table('forecast_disputes')->where('id',$id)->first()],201);
    }

    public function mine(Request $request)
    {
        $page=Forecast::where('app_id',$this->app($request)->id)->where('user_id',$request->user()->id)->latest()->paginate(30);
        return response()->json(['data'=>collect($page->items())->map(fn($f)=>$this->serialize($f,$request->user())),
            'meta'=>['total'=>$page->total(),'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage()]]);
    }

    public function ranking(Request $request)
    {
        $app=$this->app($request);$cat=$request->string('category','all')->toString();
        $rows=UserReputation::where('app_id',$app->id)->where('category',$cat)->where('resolved_count','>=',3)
            ->with('user:id,user_name,first_name,last_name,avatar')->orderByDesc('score')->orderByDesc('confidence')->limit(100)->get();
        return response()->json(['data'=>$rows]);
    }

    public function forecaster(Request $request,string $username)
    {
        $app=$this->app($request);$user=User::where('user_name',$username)->firstOrFail();
        return response()->json(['user'=>$user->only(['id','user_name','first_name','last_name','avatar','about']),
            'reputations'=>UserReputation::where('app_id',$app->id)->where('user_id',$user->id)->get(),
            'forecasts'=>Forecast::publicVisible()->where('app_id',$app->id)->where('user_id',$user->id)->latest()->limit(50)->get()->map(fn($f)=>$this->serialize($f,$request->user()))]);
    }

    public function timeline(Request $request)
    {
        $rows=Forecast::publicVisible()->where('app_id',$this->app($request)->id)->whereIn('status',['open','resolving'])
            ->where('deadline_at','>=',now())->orderBy('deadline_at')->limit(200)->get()
            ->groupBy(fn(Forecast $f)=>$f->deadline_at->format('Y-m'))->map(fn($x)=>$x->map(fn($f)=>$this->serialize($f,$request->user())));
        return response()->json(['timeline'=>$rows]);
    }

    public function sitemap(Request $request)
    {
        $urls=Forecast::publicVisible()->where('app_id',$this->app($request)->id)->whereNotIn('status',['draft','cancelled'])
            ->latest('updated_at')->limit(5000)->get(['slug','updated_at']);
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://prevora.petertecnet.com.br/</loc><changefreq>hourly</changefreq></url>'
            .$urls->map(fn($f)=>'<url><loc>https://prevora.petertecnet.com.br/previsao/'.e($f->slug).'</loc><lastmod>'.$f->updated_at->toAtomString().'</lastmod></url>')->implode('')
            .'</urlset>';
        return response($xml,200)->header('Content-Type','application/xml; charset=UTF-8');
    }

    private function app(Request $request): Application
    {
        $slug=$request->header('X-App-Slug',$request->input('app_slug','prevora'));
        return Application::where('slug',$slug)->where('is_active',true)->firstOrFail();
    }

    private function serialize(Forecast $f,?User $viewer=null,bool $detail=false): array
    {
        $my=$viewer?DB::table('forecast_estimates')->where('forecast_id',$f->id)->where('user_id',$viewer->id)->latest('id')->first():null;
        $data=[
            'id'=>$f->id,'slug'=>$f->slug,'statement'=>$f->statement,'summary'=>$f->summary,'category'=>$f->category,
            'topics'=>$f->topics??[],'entities'=>$f->entities??[],'status'=>$f->status,'deadline_at'=>$f->deadline_at?->toIso8601String(),
            'locked_at'=>$f->locked_at?->toIso8601String(),'author_probability'=>$f->author_probability,
            'community_probability'=>$f->community_probability,'platform_probability'=>$f->platform_probability,
            'platform_confidence'=>$f->platform_confidence,'participant_count'=>$f->participant_count,'comment_count'=>$f->comment_count,
            'evidence_count'=>$f->evidence_count,'follow_count'=>$f->follow_count,'can_estimate'=>$f->canEstimate(),
            'my_probability'=>$my?->probability,'following'=>$viewer?DB::table('forecast_follows')->where('forecast_id',$f->id)->where('user_id',$viewer->id)->exists():false,
            'author'=>$f->relationLoaded('user')&&$f->user?['id'=>$f->user->id,'username'=>$f->user->user_name,
                'name'=>trim(($f->user->first_name??'').' '.($f->user->last_name??'')),'avatar'=>$f->user->avatar]:null,
            'created_at'=>$f->created_at?->toIso8601String(),'updated_at'=>$f->updated_at?->toIso8601String(),
        ];
        if($detail){
            $data += [
                'original_statement'=>$f->original_statement,'resolution_criteria'=>$f->resolution_criteria,
                'resolution_source_requirements'=>$f->resolution_source_requirements??[],
                'evidence'=>DB::table('forecast_evidence')->where('forecast_id',$f->id)->where('status','active')->latest()->limit(100)->get(),
                'comments'=>DB::table('forecast_comments as c')->leftJoin('users as u','u.id','=','c.user_id')
                    ->where('c.forecast_id',$f->id)->where('c.moderation_status','approved')->whereNull('c.deleted_at')
                    ->select('c.*','u.user_name','u.first_name','u.last_name','u.avatar')->latest('c.id')->limit(100)->get(),
                'versions'=>DB::table('forecast_versions')->where('forecast_id',$f->id)->orderBy('version_no')->get(),
                'resolutions'=>DB::table('forecast_resolutions')->where('forecast_id',$f->id)->latest()->get(),
            ];
        }
        return $data;
    }
}
