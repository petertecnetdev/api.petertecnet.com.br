<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PublishSocialTimelineMoments extends Command
{
    protected $signature = 'social:publish-event-moments {--limit=80}';
    protected $description = 'Publishes idempotent strategic social posts for active public events.';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 300);
        $events = DB::table('events as e')
            ->join('productions as p', 'p.id', '=', 'e.production_id')
            ->where('e.is_published', true)
            ->where('e.is_cancelled', false)
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->where(fn ($query) => $query->whereNull('e.end_date')->orWhere('e.end_date', '>', now()))
            ->whereBetween('e.start_date', [now()->subHour(), now()->addDays(8)])
            ->orderBy('e.start_date')
            ->limit($limit)
            ->get(['e.id', 'e.app_id', 'e.title', 'e.slug', 'e.start_date', 'e.production_id', 'p.user_id as owner_id']);

        $created = 0;
        foreach ($events as $event) {
            $start = Carbon::parse($event->start_date);
            $dayKey = now()->format('Y-m-d');
            $moments = [];

            if ($start->isToday()) {
                $moments[] = ['key' => 'today', 'body' => 'É hoje: '.$event->title.'. Confira os detalhes, combine com a galera e garanta seu ingresso.'];
            } elseif ($start->isTomorrow()) {
                $moments[] = ['key' => 'tomorrow', 'body' => 'É amanhã: '.$event->title.'. Os ingressos e informações do evento estão disponíveis na Cutinapp.'];
            } elseif ($start->isFuture() && now()->diffInDays($start, false) <= 7) {
                $moments[] = ['key' => 'week', 'body' => $event->title.' acontece nesta semana. Salve o evento e confira os ingressos disponíveis.'];
            }

            $ticketCapacity = (int) DB::table('tickets')->where('app_id', $event->app_id)->where('event_id', $event->id)->sum('quantity');
            if ($ticketCapacity > 0) {
                $issued = (int) DB::table('event_passes')->where('event_id', $event->id)->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])->count();
                $remaining = max(0, $ticketCapacity - $issued);
                if ($remaining > 0 && $remaining <= max(5, (int) ceil($ticketCapacity * 0.10))) {
                    $moments[] = ['key' => 'low-stock', 'body' => 'Últimas unidades para '.$event->title.'. Se você pretende ir, confira os ingressos antes que acabem.'];
                }
            }

            foreach ($moments as $moment) {
                $automationKey = 'event:'.$event->id.':'.$moment['key'].':'.$dayKey;
                $inserted = DB::table('social_posts')->insertOrIgnore([
                    'app_id' => $event->app_id,
                    'user_id' => $event->owner_id,
                    'event_id' => $event->id,
                    'parent_id' => null,
                    'client_token' => null,
                    'automation_key' => $automationKey,
                    'body' => $moment['body'],
                    'type' => 'event',
                    'status' => 'published',
                    'visibility' => 'public',
                    'source' => 'event_automation',
                    'campaign' => 'organic_event_moment',
                    'is_pinned' => false,
                    'is_promoted' => false,
                    'metadata' => json_encode(['moment' => $moment['key'], 'event_slug' => $event->slug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'published_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (! $inserted) continue;
                $postId = (int) DB::table('social_posts')->where('app_id', $event->app_id)->where('automation_key', $automationKey)->value('id');
                DB::table('social_post_metrics')->insertOrIgnore(['app_id' => $event->app_id, 'post_id' => $postId, 'created_at' => now(), 'updated_at' => now()]);
                $created++;
            }
        }

        $this->info('Strategic social posts created: '.$created);
        return self::SUCCESS;
    }
}
