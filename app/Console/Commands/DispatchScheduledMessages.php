<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Services\MessagingService;
use App\Models\Application;
use App\Support\ApplicationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchScheduledMessages extends Command
{
    protected $signature = 'messaging:dispatch-scheduled {--limit=100}';
    protected $description = 'Entrega mensagens agendadas que chegaram ao horário de envio.';

    public function handle(ApplicationContext $context, MessagingService $messaging): int
    {
        $appIds = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.scheduled_at')
            ->where('m.scheduled_at', '<=', now())
            ->whereNull('m.delivered_at')
            ->distinct()
            ->pluck('c.app_id');

        $total = 0;
        foreach ($appIds as $appId) {
            $application = Application::find((int) $appId);
            if (! $application) {
                continue;
            }
            $context->set($application);
            $total += $messaging->dispatchDueScheduled((int) $this->option('limit'));
            $context->clear();
        }

        $this->info("Mensagens entregues: {$total}");
        return self::SUCCESS;
    }
}
