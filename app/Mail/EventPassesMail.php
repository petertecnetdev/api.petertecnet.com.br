<?php

namespace App\Mail;

use App\Models\CommerceOrder;
use App\Services\ApplicationMailBrandingService;
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
        $mailBrand = app(ApplicationMailBrandingService::class)->forApplication(
            $application,
            $application?->name,
            $application?->url
        );
        $applicationName = $mailBrand['name'];
        $frontendUrl = rtrim((string) ($application?->url ?: config('app.frontend_url', config('app.url'))), '/');
        $eventPassesPath = trim((string) data_get($application?->runtime_settings ?? [], 'routes.event_passes', '/passes'));

        if ($eventPassesPath === ''
            || ! str_starts_with($eventPassesPath, '/')
            || str_starts_with($eventPassesPath, '//')) {
            $eventPassesPath = '/passes';
        }

        $passesUrl = $frontendUrl.'/'.ltrim($eventPassesPath, '/');

        $mail = $this->subject('Seus ingressos para '.$eventTitle.' | '.$applicationName)
            ->view('emails.event-passes')
            ->with([
                'frontendUrl' => $frontendUrl,
                'passesUrl' => $passesUrl,
                'applicationName' => $applicationName,
                'mailBrand' => $mailBrand,
            ]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $mailBrand['sender_name']);
        }

        return $mail;
    }
}
