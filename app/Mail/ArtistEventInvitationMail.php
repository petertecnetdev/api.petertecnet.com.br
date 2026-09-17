<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ArtistEventInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $eventTitle,
        public string $producerName,
        public string $registrationUrl,
        public ?string $participationType = null,
        public ?string $scheduledAt = null,
        public ?string $stage = null,
    ) {
    }

    public function build()
    {
        return $this
            ->subject('Convite para participar de '.$this->eventTitle.' na Cutinapp')
            ->view('emails.artist-event-invitation')
            ->with([
                'eventTitle' => $this->eventTitle,
                'producerName' => $this->producerName,
                'registrationUrl' => $this->registrationUrl,
                'participationType' => $this->participationType,
                'scheduledAt' => $this->scheduledAt,
                'stage' => $this->stage,
            ]);
    }
}
