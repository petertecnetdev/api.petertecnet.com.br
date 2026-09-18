<?php

namespace App\Mail;

use App\Models\Application;
use App\Services\ApplicationMailBrandingService;
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

        $subjectPrefix = ! empty($this->details['is_reconfirmation'])
            ? 'Confirme novamente sua participação em '
            : (! empty($this->details['is_reminder']) ? 'Lembrete: convite para ' : 'Convite para participar de ');

        $mail = $this
            ->subject($subjectPrefix.$this->eventTitle.' • '.$mailBrand['name'])
            ->view('emails.artist-event-invitation')
            ->with([
                'eventTitle' => $this->eventTitle,
                'producerName' => $this->producerName,
                'actionUrl' => $this->actionUrl,
                'participationType' => $this->participationType,
                'scheduledAt' => $this->scheduledAt,
                'stage' => $this->stage,
                'details' => $this->details,
                'mailBrand' => $mailBrand,
            ]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $mailBrand['sender_name']);
        }

        return $mail;
    }
}
