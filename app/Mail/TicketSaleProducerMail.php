<?php

namespace App\Mail;

use App\Models\CommerceOrder;
use App\Services\ApplicationMailBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketSaleProducerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CommerceOrder $order, public int $ticketQuantity) {}

    public function build(): self
    {
        $this->order->loadMissing(['event.application','production','user']);
        $eventTitle = $this->order->event?->title ?? 'evento';
        $application = $this->order->event?->application;
        $mailBrand = app(ApplicationMailBrandingService::class)->forApplication(
            $application,
            $application?->name ?: 'Cutinapp',
            $application?->url ?: 'https://cutinapp.petertecnet.com.br'
        );
        $applicationName = $mailBrand['name'];
        $frontendUrl = rtrim((string)($application?->url ?: 'https://cutinapp.petertecnet.com.br'), '/');
        $saleUrl = $frontendUrl.'/producer/sales/'.($this->order->production_id ?: '').'/'.$this->order->public_id;

        $mail = $this->subject('Nova venda de ingresso: '.$eventTitle.' | '.$applicationName)
            ->view('emails.ticket-sale-producer')
            ->with([
                'applicationName' => $applicationName,
                'saleUrl' => $saleUrl,
                'mailBrand' => $mailBrand,
            ]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $mailBrand['sender_name']);
        }

        return $mail;
    }
}
