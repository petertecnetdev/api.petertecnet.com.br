<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class KryvionOpportunityReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $report,
        public string $recipientName,
    ) {}

    public function build()
    {
        return $this
            ->subject('Kryvion Radar: '.$this->report['symbol'].' entrou em sinal forte')
            ->view('emails.kryvion-opportunity-report')
            ->with([
                'report' => $this->report,
                'recipientName' => $this->recipientName,
            ]);
    }
}
