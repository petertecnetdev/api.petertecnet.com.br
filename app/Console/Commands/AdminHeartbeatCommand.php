<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminHeartbeatCommand extends Command
{
    protected $signature = 'admin:heartbeat {service=scheduler} {--status=healthy}';
    protected $description = 'Registra heartbeat operacional para o Peter Tecnet Command Center.';

    public function handle(): int
    {
        if (! Schema::hasTable('admin_runtime_heartbeats')) return self::SUCCESS;
        $service=(string)$this->argument('service');
        DB::table('admin_runtime_heartbeats')->updateOrInsert(
            ['service'=>$service],
            ['status'=>(string)$this->option('status'),'last_seen_at'=>now(),'updated_at'=>now(),'created_at'=>now()]
        );
        return self::SUCCESS;
    }
}
