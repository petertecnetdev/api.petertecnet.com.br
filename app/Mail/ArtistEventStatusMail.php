<?php

namespace App\Mail;

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
    ) {
    }

    public function build()
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.artist-event-status')
            ->with([
                'heading' => $this->heading,
                'messageText' => $this->messageText,
                'eventTitle' => $this->eventTitle,
                'eventDate' => $this->eventDate,
                'producerName' => $this->producerName,
            ]);
    }
}
