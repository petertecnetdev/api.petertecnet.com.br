<?php

namespace App\Services;

use App\Mail\TicketSaleProducerMail;
use App\Models\CommerceOrder;
use App\Models\ImportantEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ImportantEventService
{
    public function recordTicketPurchase(CommerceOrder $order): ?ImportantEvent
    {
        $order->loadMissing(['event.application','production.user','user','items']);
        if ($order->status !== 'paid' || !$order->event) return null;

        $ticketQuantity = (int) $order->items->where('type','ticket')->sum('quantity');
        if ($ticketQuantity <= 0) return null;

        $event = $order->event;
        $production = $order->production;
        $producer = $production?->user;
        $buyer = $order->user;
        $application = $event->application;
        $dedupeKey = 'ticket.purchase.completed:commerce_order:'.$order->id;
        $producerUrl = $production ? '/producer/sales/'.$production->id.'/'.$order->public_id : '/producer/sales';

        $metadata = [
            'order_id'=>(int)$order->id,
            'order_public_id'=>(string)$order->public_id,
            'event_id'=>(int)$event->id,
            'event_title'=>(string)$event->title,
            'production_id'=>$production?->id ? (int)$production->id : null,
            'production_name'=>$production?->name,
            'buyer_id'=>$buyer?->id ? (int)$buyer->id : null,
            'buyer_name'=>$this->userName($buyer),
            'producer_user_id'=>$producer?->id ? (int)$producer->id : null,
            'ticket_count'=>$ticketQuantity,
            'currency'=>(string)($order->currency ?: 'BRL'),
            'total'=>(float)$order->total,
            'platform_fee'=>(float)$order->platform_fee,
            'processor_fee'=>(float)$order->processor_fee,
            'producer_net'=>(float)$order->producer_net,
            'app_slug'=>$application?->slug,
            'producer_url'=>$producerUrl,
            'producer_email_status'=>$producer?->email ? 'pending' : 'unavailable',
        ];

        $attributes = [
            'app_id'=>(int)$event->app_id,
            'type'=>'ticket.purchase.completed',
            'severity'=>'success',
            'title'=>'Nova venda de ingresso',
            'message'=>$ticketQuantity.' '.($ticketQuantity===1?'ingresso vendido':'ingressos vendidos').' para '.$event->title.'.',
            'actor_user_id'=>$buyer?->id,
            'reference_type'=>'commerce_order',
            'reference_id'=>(string)$order->id,
            'reference_url'=>$producerUrl,
            'metadata'=>$metadata,
            'occurred_at'=>$order->paid_at ?: now(),
        ];

        try {
            $importantEvent = ImportantEvent::query()->firstOrCreate(['dedupe_key'=>$dedupeKey], $attributes);
        } catch (QueryException $e) {
            $importantEvent = ImportantEvent::query()->where('dedupe_key',$dedupeKey)->first();
            if (!$importantEvent) throw $e;
        }

        $delivery = app(DeliveryEffectService::class);
        $deliveryContext = [
            'order_id'=>(int)$order->id,
            'event_id'=>(int)$event->id,
            'producer_user_id'=>$producer?->id ? (int)$producer->id : null,
            'important_event_id'=>(int)$importantEvent->id,
        ];

        if ($producer?->id) {
            $delivery->run((int)$event->app_id,'commerce_order',(int)$order->id,'producer_ticket_sale_notification',function()use($event,$producer,$order,$ticketQuantity,$producerUrl,$metadata,$importantEvent){
                app(AppNotificationService::class)->sendToUser((int)$event->app_id,(int)$producer->id,[
                    'type'=>'ticket_sale_completed',
                    'title'=>'Nova venda de ingresso',
                    'message'=>$this->producerMessage($order,$ticketQuantity),
                    'reference_type'=>'commerce_order',
                    'reference_id'=>$order->id,
                    'reference_url'=>$producerUrl,
                    'send_email'=>false,
                    'data'=>array_merge($metadata,['important_event_id'=>$importantEvent->id]),
                ]);
            },$deliveryContext);
        }

        if ($producer?->email) {
            try {
                $delivery->run((int)$event->app_id,'commerce_order',(int)$order->id,'producer_ticket_sale_email',function()use($producer,$order,$ticketQuantity,$importantEvent){
                    Mail::to($producer->email)->queue(new TicketSaleProducerMail($order,$ticketQuantity));
                    $this->updateEmailStatus($importantEvent,'delivered');
                },$deliveryContext);
            } catch (\Throwable $e) {
                $this->updateEmailStatus($importantEvent,'failed');
                Log::error('Falha ao enviar e-mail de nova venda ao produtor; entrega ficará pendente para nova reconciliação.',[
                    'important_event_id'=>$importantEvent->id,
                    'order_id'=>$order->id,
                    'producer_user_id'=>$producer->id,
                    'message'=>$e->getMessage(),
                ]);
            }
        }

        return $importantEvent->fresh();
    }

    private function producerMessage(CommerceOrder $order,int $ticketQuantity): string
    {
        $buyer = $this->userName($order->user) ?: 'Um participante';
        $amount = number_format((float)$order->total,2,',','.');
        return $buyer.' comprou '.$ticketQuantity.' '.($ticketQuantity===1?'ingresso':'ingressos').' para '.$order->event->title.'. Total do pedido: R$ '.$amount.'.';
    }

    private function userName($user): ?string
    {
        if (!$user) return null;
        $name = trim(implode(' ',array_filter([$user->first_name ?? null,$user->last_name ?? null])));
        return $name !== '' ? $name : ($user->user_name ?: $user->email);
    }

    private function updateEmailStatus(ImportantEvent $event,string $status): void
    {
        $metadata = (array)$event->metadata;
        $metadata['producer_email_status'] = $status;
        $metadata['producer_email_updated_at'] = now()->toIso8601String();
        $event->forceFill(['metadata'=>$metadata])->save();
    }
}
