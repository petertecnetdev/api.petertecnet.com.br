<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Establishment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class EstablishmentEngagementSummaryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public Establishment $establishment,
        public string $recipientName,
        public array $report,
        public string $communicationId,
        public array $ctas,
        public ?string $trackingPixelUrl = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject((string) $this->report['subject'])
            ->view('emails.establishment-engagement-summary');
    }
}
