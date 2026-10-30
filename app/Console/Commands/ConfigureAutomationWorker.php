<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConfigureAutomationWorker extends Command
{
    protected $signature = 'automation:configure
        {application : Slug da aplicação}
        {worker : Worker registrado no servidor}
        {--enable : Habilitar}
        {--disable : Desabilitar}
        {--interval= : Intervalo em minutos (5 a 1440)}';

    protected $description = 'Habilita ou pausa um worker específico por aplicação.';

    public function handle(): int
    {
        if ((bool) $this->option('enable') === (bool) $this->option('disable')) {
            $this->error('Informe exatamente uma das opções --enable ou --disable.');
            return self::FAILURE;
        }

        $key = (string) $this->argument('worker');
        $definition = config('automation_workers.workers.'.$key);
        if (! is_array($definition)) {
            $this->error('Worker não registrado.');
            return self::FAILURE;
        }

        $app = DB::table('applications')
            ->where('slug', (string) $this->argument('application'))
            ->where('is_active', true)
            ->first(['id', 'slug']);

        if (! $app) {
            $this->error('Aplicação não encontrada ou inativa.');
            return self::FAILURE;
        }

        $requested = $this->option('interval');
        if ($requested !== null && (! ctype_digit((string) $requested)
            || (int) $requested < 5 || (int) $requested > 1440)) {
            $this->error('Intervalo deve estar entre 5 e 1440 minutos.');
            return self::FAILURE;
        }

        $interval = $requested !== null
            ? (int) $requested
            : (int) ($definition['interval_minutes'] ?? 60);

        $identity = ['app_id' => $app->id, 'worker_key' => $key];
        $existing = DB::table('automation_worker_controls')->where($identity)->first();

        $values = [
            'enabled' => (bool) $this->option('enable'),
            'interval_minutes' => $interval,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('automation_worker_controls')->where($identity)->update($values);
        } else {
            DB::table('automation_worker_controls')->insert(array_merge($identity, $values, [
                'created_at' => now(),
            ]));
        }

        Log::notice('automation.worker_configuration_changed', [
            'application_id' => $app->id,
            'worker_key' => $key,
            'enabled' => $values['enabled'],
            'interval_minutes' => $interval,
            'actor' => 'artisan_operator',
        ]);

        $this->info("Worker {$key} ".($values['enabled'] ? 'habilitado' : 'pausado')." para {$app->slug}.");
        return self::SUCCESS;
    }
}
