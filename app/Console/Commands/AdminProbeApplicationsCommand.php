<?php

namespace App\Console\Commands;

use App\Events\EcosystemUpdated;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class AdminProbeApplicationsCommand extends Command
{
    protected $signature = 'admin:probe-apps {--timeout=8}';
    protected $description = 'Verifica disponibilidade e latência dos produtos Peter Tecnet.';

    public function handle(): int
    {
        if (! Schema::hasTable('admin_service_probes')) return self::SUCCESS;
        $changed=false;
        foreach (Application::query()->where('is_active',true)->whereNotNull('url')->get(['id','name','url']) as $app) {
            $started=microtime(true);$status='down';$httpStatus=null;$error=null;
            try {
                $response=Http::timeout((int)$this->option('timeout'))->retry(1,200)->withHeaders(['User-Agent'=>'PeterTecnet-CommandCenter/1.0'])->get($app->url);
                $httpStatus=$response->status();
                $status=$response->successful() || in_array($httpStatus,[301,302,401,403],true) ? 'operational' : ($httpStatus>=500?'down':'degraded');
            } catch (\Throwable $e) {
                $error=mb_substr($e->getMessage(),0,500);
            }
            $latency=(int)round((microtime(true)-$started)*1000);
            $previous=DB::table('admin_service_probes')->where('application_id',$app->id)->latest('checked_at')->first();
            DB::table('admin_service_probes')->insert(['application_id'=>$app->id,'status'=>$status,'http_status'=>$httpStatus,'latency_ms'=>$latency,'error'=>$error,'checked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('admin_service_probes')->where('application_id',$app->id)->where('checked_at','<',now()->subDays(7))->delete();
            if($previous && $previous->status!==$status)$changed=true;
            $this->line("{$app->name}: {$status} {$httpStatus} {$latency}ms");
        }
        if($changed) broadcast(new EcosystemUpdated(['command','applications'],'health.changed'));
        return self::SUCCESS;
    }
}
