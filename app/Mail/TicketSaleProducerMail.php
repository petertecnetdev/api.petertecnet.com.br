<?php

namespace App\Mail;

use App\Models\CommerceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketSaleProducerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CommerceOrder $order, public int $ticketQuantity) {}

    public function build(): self
    {
        $this->order->loadMissing(['event.application', 'production', 'user']);
        $eventTitle = $this->order->event?->title ?? 'evento';
        $application = $this->order->event?->application;
        $applicationName = $application?->name ?? 'Cutinapp';
        $frontendUrl = rtrim((string) ($application?->url ?: 'https://cutinapp.petertecnet.com.br'), '/');
        $saleUrl = $frontendUrl.'/producer/sales/'.($this->order->production_id ?: '').'/'.$this->order->public_id;

        return $this->subject('Nova venda de ingresso: '.$eventTitle.' | '.$applicationName)
            ->view('emails.ticket-sale-producer')
            ->with([
                'applicationName' => $applicationName,
                'saleUrl' => $saleUrl,
            ]);
    }
}
