<?php

namespace App\Mail;

use App\Models\CommerceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class EventPassesMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CommerceOrder $order, public Collection $passes) {}

    public function build(): self
    {
        $event = $this->order->event;
        $application = $event?->application;
        $eventTitle = $event?->title ?? 'seu evento';
        $applicationName = $application?->name ?? config('app.name', 'Peter Tecnet');
        $frontendUrl = rtrim((string) ($application?->url ?: config('app.frontend_url', config('app.url'))), '/');

        return $this->subject('Seus ingressos para '.$eventTitle.' | '.$applicationName)
            ->view('emails.event-passes')
            ->with(['frontendUrl' => $frontendUrl, 'applicationName' => $applicationName]);
    }
}
