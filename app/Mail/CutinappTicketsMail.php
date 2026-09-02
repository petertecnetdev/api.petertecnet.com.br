<?php

namespace App\Mail;

use App\Models\CutinappOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class CutinappTicketsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CutinappOrder $order,
        public Collection $passes
    ) {
    }

    public function build(): self
    {
        $eventTitle = $this->order->event?->title ?? 'seu evento';

        return $this
            ->subject('Seus ingressos para ' . $eventTitle . ' | Cutinapp')
            ->view('emails.cutinapp-tickets')
            ->with([
                'frontendUrl' => rtrim((string) config('services.cutinapp.frontend_url', 'https://cutinapp.petertecnet.com.br'), '/'),
            ]);
    }
}
