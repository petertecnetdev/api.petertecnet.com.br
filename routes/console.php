<?php

use App\Models\IdentityAuthEvent;
use App\Models\IdentitySession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('identity:prune', function () {
    $auditCutoff = now()->subDays(max(30, (int) config('identity.audit_retention_days', 180)));
    $sessionCutoff = now()->subDays(90);

    $events = IdentityAuthEvent::query()
        ->where('occurred_at', '<', $auditCutoff)
        ->delete();

    $sessions = IdentitySession::query()
        ->where(function ($query) use ($sessionCutoff) {
            $query->where('revoked_at', '<', $sessionCutoff)
                ->orWhere('refresh_expires_at', '<', $sessionCutoff);
        })
        ->delete();

    $this->info("Peter Identity: {$events} evento(s) e {$sessions} sessão(ões) antigas removidos.");
})->purpose('Remove eventos e sessões expiradas do Peter Identity conforme a política de retenção.');

Schedule::command('identity:prune')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onOneServer();
