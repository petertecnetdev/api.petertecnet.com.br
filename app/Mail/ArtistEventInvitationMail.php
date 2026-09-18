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
        public string $actionUrl,
        public ?string $participationType = null,
        public ?string $scheduledAt = null,
        public ?string $stage = null,
        public array $details = [],
    ) {
    }

    public function build()
    {
        $subjectPrefix = ! empty($this->details['is_reconfirmation'])
            ? 'Confirme novamente sua participação em '
            : (! empty($this->details['is_reminder']) ? 'Lembrete: convite para ' : 'Convite para participar de ');

        return $this
            ->subject($subjectPrefix.$this->eventTitle)
            ->view('emails.artist-event-invitation')
            ->with([
                'eventTitle' => $this->eventTitle,
                'producerName' => $this->producerName,
                'actionUrl' => $this->actionUrl,
                'participationType' => $this->participationType,
                'scheduledAt' => $this->scheduledAt,
                'stage' => $this->stage,
                'details' => $this->details,
            ]);
    }
}
