<?php

namespace App\Domain\Engagement\Services;

use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Interaction;
use App\Models\Ticket;
use Illuminate\Support\Collection;

final class EstablishmentEngagementReportService
{
    public function generate(Establishment $establishment, Application $application): array
    {
        $events = Event::query()
            ->where('app_id', $application->id)
            ->where('production_id', $establishment->id)
            ->orderBy('start_date')
            ->get(['id', 'title', 'slug', 'start_date', 'end_date', 'is_published', 'is_cancelled', 'image']);

        $eventIds = $events->pluck('id');
        $now = now(config('app.timezone', 'America/Sao_Paulo'));
        $futureEvents = $events->filter(fn (Event $event) => $event->start_date && $event->start_date->gte($now) && ! $event->is_cancelled);
        $publishedEvents = $events->filter(fn (Event $event) => $event->is_published && ! $event->is_cancelled);
        $draftEvents = $events->filter(fn (Event $event) => ! $event->is_published && ! $event->is_cancelled);

        $ticketQuery = Ticket::query()->whereIn('event_id', $eventIds);
        $ticketCapacity = (int) (clone $ticketQuery)->sum('quantity');
        $ticketTypes = (int) (clone $ticketQuery)->count();

        $validPasses = EventPass::query()
            ->whereIn('event_id', $eventIds)
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back']);
        $issuedTickets = (int) (clone $validPasses)->count();
        $courtesies = (int) (clone $validPasses)->whereNull('commerce_order_item_id')->count();

        $paidOrderIds = CommerceOrder::query()
            ->where('app_id', $application->id)
            ->where('production_id', $establishment->id)
            ->where('status', 'paid')
            ->pluck('id');

        $paidTicketItems = CommerceOrderItem::query()
            ->whereIn('order_id', $paidOrderIds)
            ->whereNotNull('ticket_id');
        $soldTickets = $paidOrderIds->isEmpty() ? 0 : (int) (clone $paidTicketItems)->sum('quantity');
        $revenue = $paidOrderIds->isEmpty() ? 0.0 : (float) (clone $paidTicketItems)->sum('subtotal');
        $paidOrdersCount = $paidOrderIds->isEmpty() ? 0 : (int) (clone $paidTicketItems)->distinct()->count('order_id');

        $views = $eventIds->isEmpty() ? 0 : (int) Interaction::query()
            ->whereIn('entity_id', $eventIds)
            ->whereIn('entity_type', ['event', 'Event'])
            ->where('interaction_type', 'view')
            ->count();

        $conversionRate = $views > 0 ? round(($soldTickets / $views) * 100, 1) : null;
        $sellThroughRate = $ticketCapacity > 0 ? round(($issuedTickets / $ticketCapacity) * 100, 1) : null;
        $nextEvent = $futureEvents->sortBy('start_date')->first();
        $topEvent = $this->topEvent($events, $eventIds);
        $baseUrl = $this->baseUrl($application);

        $ctas = [
            ['key' => 'production', 'label' => 'Ver minha produção', 'url' => $baseUrl.'/production/'.$establishment->slug.'/public', 'primary' => true],
            ['key' => 'agenda', 'label' => 'Ver minha agenda', 'url' => $baseUrl.'/agenda/'.$establishment->slug, 'primary' => false],
            ['key' => 'events', 'label' => 'Gerenciar meus eventos', 'url' => $baseUrl.'/event/manage', 'primary' => false],
            ['key' => 'create_event', 'label' => 'Criar próximo evento', 'url' => $baseUrl.'/event/create', 'primary' => false],
        ];
        if ($nextEvent?->slug) {
            array_splice($ctas, 1, 0, [[
                'key' => 'next_event', 'label' => 'Abrir próximo evento',
                'url' => $baseUrl.'/event/'.$nextEvent->slug, 'primary' => false,
            ]]);
        }

        $metrics = [
            'events_total' => $events->count(), 'events_future' => $futureEvents->count(),
            'events_published' => $publishedEvents->count(), 'events_draft' => $draftEvents->count(),
            'ticket_types' => $ticketTypes, 'ticket_capacity' => $ticketCapacity,
            'tickets_issued' => $issuedTickets, 'tickets_sold' => $soldTickets,
            'courtesies' => $courtesies, 'paid_orders' => $paidOrdersCount,
            'revenue' => round($revenue, 2), 'event_views' => $views,
            'conversion_rate' => $conversionRate, 'sell_through_rate' => $sellThroughRate,
        ];

        return [
            'subject' => $this->subject($establishment, $metrics),
            'headline' => $this->headline($metrics),
            'intro' => $this->intro($metrics, $topEvent),
            'metrics' => $metrics,
            'insights' => $this->insights($metrics, $nextEvent, $draftEvents),
            'next_event' => $nextEvent ? $this->eventPayload($nextEvent) : null,
            'top_event' => $topEvent ? $this->eventPayload($topEvent['event'], $topEvent['issued']) : null,
            'ctas' => $ctas,
            'period_label' => 'situação atual da produção',
            'generated_at' => $now->toIso8601String(),
        ];
    }

    private function topEvent(Collection $events, Collection $eventIds): ?array
    {
        if ($eventIds->isEmpty()) return null;
        $row = EventPass::query()->selectRaw('event_id, COUNT(*) as issued')
            ->whereIn('event_id', $eventIds)
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->groupBy('event_id')->orderByDesc('issued')->first();
        if (! $row) return null;
        $event = $events->firstWhere('id', (int) $row->event_id);
        return $event ? ['event' => $event, 'issued' => (int) $row->issued] : null;
    }

    private function insights(array $metrics, ?Event $nextEvent, Collection $draftEvents): array
    {
        $insights = [];
        if ($metrics['events_future'] === 0) $insights[] = ['type'=>'opportunity','title'=>'Mantenha sua agenda ativa','message'=>'Sua produção ainda não tem um próximo evento publicado. Cadastrar a próxima data mantém sua página ativa e dá ao público um motivo para voltar.'];
        if ($draftEvents->isNotEmpty()) $insights[] = ['type'=>'action','title'=>'Você tem evento aguardando publicação','message'=>'Há '.$draftEvents->count().' evento(s) em rascunho. Revise as informações e publique quando estiver pronto para começar a divulgação e as vendas.'];
        if ($metrics['event_views'] >= 100 && $metrics['conversion_rate'] !== null && $metrics['conversion_rate'] < 5) $insights[] = ['type'=>'opportunity','title'=>'Seu evento chama atenção, mas pode converter mais','message'=>'Há tráfego na página, porém a proporção de ingressos vendidos ainda está baixa. Reforce o flyer, a oferta e o link direto de compra nas divulgações.'];
        if ($nextEvent?->start_date && $nextEvent->start_date->between(now(), now()->addDays(7))) $insights[] = ['type'=>'action','title'=>'Seu próximo evento está perto','message'=>'Este é um bom momento para redistribuir o flyer, compartilhar o link do evento e reforçar a chamada para compra antecipada.'];
        if ($metrics['tickets_sold'] > 0) $insights[] = ['type'=>'positive','title'=>'A plataforma já está gerando vendas','message'=>'Você já registrou '.$metrics['tickets_sold'].' ingresso(s) vendido(s) pela plataforma. Continue direcionando o público para o checkout para concentrar vendas e dados em um só lugar.'];
        elseif ($metrics['events_published'] > 0) $insights[] = ['type'=>'opportunity','title'=>'Transforme visualizações em primeiras vendas','message'=>'Seus eventos já podem ser divulgados. Compartilhe o link da plataforma para que o público descubra o evento e conclua a compra diretamente por lá.'];
        if ($insights === []) $insights[] = ['type'=>'positive','title'=>'Continue movimentando sua produção','message'=>'Mantenha eventos, informações e agenda atualizados. Cada atualização deixa sua página mais útil para o público e facilita novas vendas.'];
        return array_slice($insights, 0, 4);
    }

    private function subject(Establishment $establishment, array $metrics): string
    {
        return $metrics['tickets_sold'] > 0 ? $establishment->name.': veja suas vendas e próximos passos' : $establishment->name.': veja como está sua produção na plataforma';
    }

    private function headline(array $metrics): string
    {
        if ($metrics['tickets_sold'] > 0) return 'Sua produção está ganhando movimento';
        if ($metrics['events_future'] > 0) return 'Sua agenda já está pronta para receber mais público';
        return 'Vamos deixar sua produção ainda mais ativa';
    }

    private function intro(array $metrics, ?array $topEvent): string
    {
        $parts = [];
        if ($metrics['events_published'] > 0) $parts[] = 'Você tem '.$metrics['events_published'].' evento(s) publicado(s)';
        if ($metrics['tickets_sold'] > 0) $parts[] = $metrics['tickets_sold'].' ingresso(s) vendido(s)';
        if ($metrics['event_views'] > 0) $parts[] = $metrics['event_views'].' visualização(ões) nos eventos';
        $intro = $parts !== [] ? implode(', ', $parts).'.' : 'Reunimos os principais dados da sua produção para facilitar as próximas decisões.';
        if ($topEvent) $intro .= ' O evento com maior emissão de ingressos no momento é '.$topEvent['event']->title.'.';
        return $intro;
    }

    private function eventPayload(Event $event, ?int $issued = null): array
    {
        return ['id'=>$event->id,'title'=>$event->title,'slug'=>$event->slug,'start_date'=>$event->start_date?->toIso8601String(),'image'=>$event->image,'issued'=>$issued];
    }

    private function baseUrl(Application $application): string
    {
        $url = trim((string) $application->url);
        if ($url === '') $url = 'https://'.trim((string) $application->slug).'.petertecnet.com.br';
        return rtrim($url, '/');
    }
}
