<?php

namespace App\Mail;

use App\Models\Application;
use App\Services\ApplicationMailBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class ArtistEventStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $heading,
        public string $messageText,
        public string $eventTitle,
        public ?string $eventDate = null,
        public ?string $producerName = null,
        public ?Application $application = null,
    ) {
    }

    public function build()
    {
        $mailBrand = app(ApplicationMailBrandingService::class)->forApplication(
            $this->application,
            'Peter Tecnet',
            config('app.url')
        );

        $mail = $this
            ->subject($this->subjectLine.' • '.$mailBrand['name'])
            ->view('emails.artist-event-status')
            ->with([
                'heading' => $this->heading,
                'messageText' => $this->messageText,
                'eventTitle' => $this->eventTitle,
                'eventDate' => $this->eventDate,
                'producerName' => $this->producerName,
                'mailBrand' => $mailBrand,
            ]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $mailBrand['sender_name']);
        }

        return $mail;
    }
}
