<?php

namespace App\Http\Controllers\Admin;

use App\Events\EcosystemUpdated;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommandCenterController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'operations_view');
        $now = now();

        $apps = Application::query()->orderBy('name')->get(['id','name','slug','url','logo','version','is_active']);
        $interactionColumns = Schema::hasTable('interactions') ? Schema::getColumnListing('interactions') : [];
        $hasOutcome = in_array('outcome', $interactionColumns, true);
        $hasDuration = in_array('duration_ms', $interactionColumns, true);

        $applications = $apps->map(function ($app) use ($now, $hasOutcome, $hasDuration) {
            $base = Schema::hasTable('interactions') ? DB::table('interactions')->where('app_id', $app->id) : null;
            $lastActivity = $base ? (clone $base)->max('created_at') : null;
            $requests24h = $base ? (clone $base)->where('created_at', '>=', $now->copy()->subDay())->count() : 0;
            $errors24h = $base && $hasOutcome ? (clone $base)->where('created_at', '>=', $now->copy()->subDay())->where('outcome', 'error')->count() : 0;
            $latency = $base && $hasDuration ? (int) round((float) (clone $base)->where('created_at','>=',$now->copy()->subHour())->whereNotNull('duration_ms')->avg('duration_ms')) : null;
            $probe = Schema::hasTable('admin_service_probes') ? DB::table('admin_service_probes')->where('application_id',$app->id)->latest('checked_at')->first() : null;

            $status = ! $app->is_active ? 'disabled' : ($probe?->status ?: ($lastActivity && strtotime($lastActivity) >= $now->copy()->subHours(24)->timestamp ? 'operational' : 'unknown'));
            $errorRate = $requests24h ? round(($errors24h / $requests24h) * 100, 2) : 0;
            if ($status === 'operational' && $errorRate >= 10) $status = 'degraded';

            return [
                'id'=>$app->id,'name'=>$app->name,'slug'=>$app->slug,'url'=>$app->url,'logo'=>$app->logo,'version'=>$app->version,
                'status'=>$status,'last_activity_at'=>$lastActivity,'requests_24h'=>$requests24h,'errors_24h'=>$errors24h,
                'error_rate_24h'=>$errorRate,'avg_latency_ms_1h'=>$latency,'probe_http_status'=>$probe?->http_status,
                'probe_latency_ms'=>$probe?->latency_ms,'last_probe_at'=>$probe?->checked_at,
            ];
        });

        $queues = $this->queueSnapshot();
        $runtime = $this->runtimeSnapshot();
        $security = $this->securitySnapshot();
        $incidents = Schema::hasTable('admin_incidents')
            ? DB::table('admin_incidents')->whereIn('status',['open','acknowledged','investigating'])->orderByRaw("FIELD(severity,'critical','warning','info')")->orderByDesc('created_at')->limit(20)->get()
            : collect();

        $criticalApps = $applications->whereIn('status',['down','degraded'])->count();
        $score = 100;
        $score -= min($criticalApps * 12, 36);
        $score -= min(($queues['failed'] ?? 0) * 4, 20);
        $score -= min(($security['critical_events_24h'] ?? 0) * 4, 20);
        $score -= min(($security['suspicious_24h'] ?? 0) * 1, 8);
        $score -= min($incidents->where('severity','critical')->count() * 8, 24);
        if (($runtime['scheduler']['status'] ?? 'unknown') !== 'healthy') $score -= 15;
        $score = max(0, $score);

        return response()->json([
            'score'=>$score,
            'status'=>$score >= 90 ? 'healthy' : ($score >= 70 ? 'attention' : 'critical'),
            'applications'=>$applications,
            'queues'=>$queues,
            'runtime'=>$runtime,
            'security'=>$security,
            'incidents'=>$incidents,
            'summary'=>[
                'applications'=>$applications->count(),
                'operational'=>$applications->where('status','operational')->count(),
                'degraded'=>$applications->where('status','degraded')->count(),
                'down'=>$applications->where('status','down')->count(),
                'unknown'=>$applications->where('status','unknown')->count(),
                'open_incidents'=>$incidents->count(),
                'critical_incidents'=>$incidents->where('severity','critical')->count(),
            ],
            'generated_at'=>$now->toIso8601String(),
        ]);
    }

    public function globalSearch(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'ecosystem_manage');
        $data = $request->validate(['q'=>['required','string','min:2','max:160']]);
        $q = trim($data['q']);
        $like = "%{$q}%";
        $out = [];

        if (Schema::hasTable('users')) {
            $out['users'] = DB::table('users')->select('id','first_name','last_name','user_name','email','created_at')
                ->where(function($x) use ($like,$q){$x->where('email','like',$like)->orWhere('user_name','like',$like)->orWhere('first_name','like',$like)->orWhere('last_name','like',$like);if(ctype_digit($q))$x->orWhere('id',(int)$q);})
                ->limit(12)->get();
        }
        if (Schema::hasTable('applications')) {
            $out['applications'] = DB::table('applications')->select('id','name','slug','url','version','is_active')->where('name','like',$like)->orWhere('slug','like',$like)->limit(12)->get();
        }
        if (Schema::hasTable('establishments')) {
            $out['establishments'] = DB::table('establishments')->select('id','name','fantasy','slug','city','uf','app_id')->where('name','like',$like)->orWhere('fantasy','like',$like)->orWhere('slug','like',$like)->limit(12)->get();
        }
        if (Schema::hasTable('items')) {
            $out['items'] = DB::table('items')->select('id','name','slug','price','app_id','establishment_id')->where('name','like',$like)->orWhere('slug','like',$like)->limit(12)->get();
        }
        if (Schema::hasTable('productions')) {
            $out['productions'] = DB::table('productions')->select('id','name','slug','user_id','app_id')->where('name','like',$like)->orWhere('slug','like',$like)->limit(12)->get();
        }
        if (Schema::hasTable('events')) {
            $out['events'] = DB::table('events')->select('id','title','slug','production_id','start_date')->where('title','like',$like)->orWhere('slug','like',$like)->limit(12)->get();
        }
        if (Schema::hasTable('cutinapp_orders')) {
            $out['orders'] = DB::table('cutinapp_orders as o')->leftJoin('users as u','u.id','=','o.user_id')->leftJoin('events as e','e.id','=','o.event_id')
                ->select('o.id','o.public_id','o.status','o.total','o.created_at','u.email as buyer_email','e.title as event_title')
                ->where(function($x) use ($like,$q){$x->where('o.public_id','like',$like)->orWhere('u.email','like',$like)->orWhere('e.title','like',$like);if(ctype_digit($q))$x->orWhere('o.id',(int)$q);})
                ->limit(12)->get();
        }
        if (Schema::hasTable('ecosystem_payments')) {
            $out['payments'] = DB::table('ecosystem_payments')->select('id','app_slug','provider','provider_payment_id','source_reference','status','gross_amount','created_at')
                ->where(function($x) use ($like,$q){$x->where('provider_payment_id','like',$like)->orWhere('source_reference','like',$like)->orWhere('app_slug','like',$like);if(ctype_digit($q))$x->orWhere('id',(int)$q);})
                ->limit(12)->get();
        }

        return response()->json(['query'=>$q,'groups'=>$out,'total'=>collect($out)->sum(fn($rows)=>count($rows))]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'incident_manage');
        if (! Schema::hasTable('admin_incidents')) return response()->json(['data'=>[]]);
        $query = DB::table('admin_incidents as i')->leftJoin('applications as a','a.id','=','i.application_id')->leftJoin('users as u','u.id','=','i.assigned_to')
            ->select('i.*','a.name as application_name','u.email as assignee_email');
        if ($request->filled('status')) $query->where('i.status',$request->string('status'));
        if ($request->filled('severity')) $query->where('i.severity',$request->string('severity'));
        return response()->json($query->orderByDesc('i.created_at')->paginate(50));
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'incident_manage');
        $data = $request->validate([
            'title'=>['required','string','max:180'],'description'=>['nullable','string','max:5000'],
            'severity'=>['required',Rule::in(['info','warning','critical'])],
            'application_id'=>['nullable','integer','exists:applications,id'],'assigned_to'=>['nullable','integer','exists:users,id'],
            'source'=>['nullable','string','max:80'],'context'=>['nullable','array'],
        ]);
        $id = DB::table('admin_incidents')->insertGetId([
            'public_id'=>'INC-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'title'=>$data['title'],'description'=>$data['description']??null,'severity'=>$data['severity'],'status'=>'open',
            'source'=>$data['source']??'manual','application_id'=>$data['application_id']??null,'assigned_to'=>$data['assigned_to']??null,
            'created_by'=>$request->user()->id,'context'=>isset($data['context'])?json_encode($data['context']):null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $incident = DB::table('admin_incidents')->find($id);
        $this->audit($request,'incident.created','admin_incidents',$id,null,(array)$incident);
        broadcast(new EcosystemUpdated(['command','incidents'],'incident.created'));
        return response()->json(['incident'=>$incident],201);
    }

    public function updateIncident(Request $request, int $incident): JsonResponse
    {
        $this->authorizeAccess($request, 'incident_manage');
        abort_unless(Schema::hasTable('admin_incidents'),404);
        $before = DB::table('admin_incidents')->find($incident);
        abort_unless($before,404,'Incidente não encontrado.');
        $data = $request->validate([
            'status'=>['nullable',Rule::in(['open','acknowledged','investigating','resolved'])],
            'severity'=>['nullable',Rule::in(['info','warning','critical'])],
            'assigned_to'=>['nullable','integer','exists:users,id'],'resolution'=>['nullable','string','max:5000'],
        ]);
        $patch = array_filter($data,fn($v)=>$v!==null);
        if (($patch['status']??null)==='acknowledged') $patch['acknowledged_at']=now();
        if (($patch['status']??null)==='resolved') $patch['resolved_at']=now();
        $patch['updated_at']=now();
        DB::table('admin_incidents')->where('id',$incident)->update($patch);
        $after = DB::table('admin_incidents')->find($incident);
        $this->audit($request,'incident.updated','admin_incidents',$incident,(array)$before,(array)$after);
        broadcast(new EcosystemUpdated(['command','incidents'],'incident.updated'));
        return response()->json(['incident'=>$after]);
    }

    public function security(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'security_view');
        return response()->json($this->securitySnapshot(true));
    }

    public function queues(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'operations_view');
        $snapshot = $this->queueSnapshot();
        $snapshot['failed_rows'] = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get(['uuid','connection','queue','exception','failed_at']) : [];
        return response()->json($snapshot);
    }

    public function retryJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAccess($request, 'operations_manage');
        abort_unless(Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->where('uuid',$uuid)->exists(),404,'Job não encontrado.');
        Artisan::call('queue:retry', ['id'=>[$uuid]]);
        $this->audit($request,'queue.retry','failed_jobs',null,['uuid'=>$uuid],['retried'=>true]);
        broadcast(new EcosystemUpdated(['command','queues'],'queue.retry'));
        return response()->json(['message'=>'Job reenviado para processamento.','uuid'=>$uuid]);
    }

    public function application(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request, 'operations_view');
        $activity = Schema::hasTable('interactions') ? DB::table('interactions')->where('app_id',$application->id) : null;
        $probe = Schema::hasTable('admin_service_probes') ? DB::table('admin_service_probes')->where('application_id',$application->id)->orderByDesc('checked_at')->limit(96)->get() : collect();
        return response()->json([
            'application'=>$application,
            'metrics'=>[
                'users'=>DB::table('application_user')->where('application_id',$application->id)->count(),
                'establishments'=>Schema::hasTable('application_establishment')?DB::table('application_establishment')->where('application_id',$application->id)->count():0,
                'items'=>Schema::hasColumn('items','app_id')?DB::table('items')->where('app_id',$application->id)->count():0,
                'interactions_24h'=>$activity?(clone $activity)->where('created_at','>=',now()->subDay())->count():0,
                'interactions_30d'=>$activity?(clone $activity)->where('created_at','>=',now()->subDays(30))->count():0,
                'last_activity_at'=>$activity?(clone $activity)->max('created_at'):null,
            ],
            'probes'=>$probe,
            'recent_activity'=>$activity?(clone $activity)->orderByDesc('id')->limit(30)->get():[],
            'incidents'=>Schema::hasTable('admin_incidents')?DB::table('admin_incidents')->where('application_id',$application->id)->orderByDesc('id')->limit(30)->get():[],
        ]);
    }

    private function queueSnapshot(): array
    {
        $queued = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        return ['queued'=>$queued,'failed'=>$failed,'oldest_available_at'=>$oldest ? date(DATE_ATOM,(int)$oldest) : null,'status'=>$failed>0?'attention':'healthy'];
    }

    private function runtimeSnapshot(): array
    {
        $heartbeat = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service','scheduler')->first() : null;
        $last = $heartbeat?->last_seen_at;
        $schedulerStatus = $last && strtotime($last) >= now()->subMinutes(3)->timestamp ? 'healthy' : 'stale';
        $backup = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service','backup')->first() : null;
        return [
            'scheduler'=>['status'=>$schedulerStatus,'last_seen_at'=>$last],
            'backup'=>['status'=>$backup?->status ?: 'unknown','last_success_at'=>$backup?->last_seen_at,'meta'=>$backup?->meta ? json_decode($backup->meta,true) : null],
            'database'=>['driver'=>DB::connection()->getDriverName(),'connected'=>true],
            'php'=>PHP_VERSION,
            'laravel'=>app()->version(),
            'environment'=>app()->environment(),
        ];
    }

    private function securitySnapshot(bool $detail=false): array
    {
        if (! Schema::hasTable('interactions')) {
            return [
                'critical_events_24h'=>0,
                'suspicious_24h'=>0,
                'attention_24h'=>0,
                'denied_24h'=>0,
                'errors_24h'=>0,
                'events'=>[],
            ];
        }

        $columns = Schema::getColumnListing('interactions');
        $base = DB::table('interactions')->where('created_at','>=',now()->subDay());
        $denied = in_array('outcome',$columns,true) ? (clone $base)->whereIn('outcome',['denied','refused'])->count() : 0;
        $errors = in_array('outcome',$columns,true) ? (clone $base)->where('outcome','error')->count() : 0;
        $critical = in_array('severity',$columns,true) ? (clone $base)->where('severity','critical')->count() : 0;
        $suspicious = in_array('severity',$columns,true) ? (clone $base)->where('severity','suspicious')->count() : 0;
        $attention = in_array('severity',$columns,true) ? (clone $base)->where('severity','attention')->count() : 0;
        $payload=[
            'critical_events_24h'=>$critical,
            'suspicious_24h'=>$suspicious,
            'attention_24h'=>$attention,
            'denied_24h'=>$denied,
            'errors_24h'=>$errors,
        ];
        if($detail){
            $query=(clone $base)->orderByDesc('id')->limit(100);
            if(in_array('severity',$columns,true))$query->whereIn('severity',['attention','suspicious','critical']);
            $payload['events']=$query->get();
        }
        return $payload;
    }

    private function authorizeAccess(Request $request, string $permission): void
    {
        $user=$request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission($permission) || $user->hasPermission('ecosystem_manage')),403,'Usuário sem permissão para acessar esta área do Command Center.');
    }

    private function audit(Request $request,string $action,string $entityType,?int $entityId,?array $before,?array $after): void
    {
        if(!Schema::hasTable('ecosystem_audit_logs'))return;
        EcosystemAuditLog::create(['user_id'=>$request->user()?->id,'action'=>$action,'entity_type'=>$entityType,'entity_id'=>$entityId,'before'=>$before,'after'=>$after,'ip'=>$request->ip(),'user_agent'=>substr((string)$request->userAgent(),0,1000)]);
    }
}
