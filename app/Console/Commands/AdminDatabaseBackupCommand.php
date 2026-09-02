<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class AdminDatabaseBackupCommand extends Command
{
    protected $signature = 'admin:backup-database {--retention=7}';
    protected $description = 'Cria backup local do banco e registra a saúde do backup no Command Center.';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('Backup automático suporta MySQL/MariaDB neste ambiente.');
            $this->heartbeat('unsupported', ['driver'=>DB::connection()->getDriverName()]);
            return self::SUCCESS;
        }

        $cfg=config('database.connections.mysql');
        $dir=storage_path('app/backups');
        File::ensureDirectoryExists($dir,0700,true);
        $filename='petertecnet-'.now()->format('Ymd-His').'.sql.gz';
        $path=$dir.DIRECTORY_SEPARATOR.$filename;

        $dump=new Process([
            'mysqldump','--single-transaction','--quick','--skip-lock-tables','--set-gtid-purged=OFF',
            '-h',(string)($cfg['host']??'127.0.0.1'),'-P',(string)($cfg['port']??3306),'-u',(string)($cfg['username']??''),(string)($cfg['database']??'')
        ],null,['MYSQL_PWD'=>(string)($cfg['password']??'')]);
        $gzip=new Process(['gzip','-c']);

        try {
            $dump->setTimeout(900);
            $dump->run();
            if(!$dump->isSuccessful()) throw new \RuntimeException(trim($dump->getErrorOutput()) ?: 'mysqldump falhou.');
            $gzip->setInput($dump->getOutput());
            $gzip->setTimeout(900);
            $gzip->run();
            if(!$gzip->isSuccessful()) throw new \RuntimeException(trim($gzip->getErrorOutput()) ?: 'gzip falhou.');
            File::put($path,$gzip->getOutput());
            @chmod($path,0600);
            if(!File::exists($path) || File::size($path)<100) throw new \RuntimeException('Arquivo de backup inválido ou vazio.');

            $retention=max((int)$this->option('retention'),1);
            foreach(File::files($dir) as $file){
                if($file->getMTime()<now()->subDays($retention)->timestamp) @unlink($file->getPathname());
            }
            $this->heartbeat('healthy',['file'=>$filename,'bytes'=>File::size($path),'retention_days'=>$retention]);
            $this->info("Backup criado: {$filename}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            @unlink($path);
            $this->heartbeat('failed',['error'=>mb_substr($e->getMessage(),0,500)]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function heartbeat(string $status,array $meta): void
    {
        if(!Schema::hasTable('admin_runtime_heartbeats'))return;
        DB::table('admin_runtime_heartbeats')->updateOrInsert(['service'=>'backup'],[
            'status'=>$status,'last_seen_at'=>now(),'meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE),'updated_at'=>now(),'created_at'=>now(),
        ]);
    }
}
