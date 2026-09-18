<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProducerSalesReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $manageUrl;
    public string $createEventUrl;
    public ?string $eventUrl;

    public function __construct(
        public User $user,
        public Application $application,
        public Production $production,
        public mixed $firstEvent = null,
    ) {
        $base = rtrim((string) $application->url, '/');
        $this->manageUrl = $base.'/producer/onboarding?productionId='.$production->id;
        $this->createEventUrl = $base.'/event/create?productionId='.$production->id;
        $this->eventUrl = $firstEvent?->slug ? $base.'/event/'.$firstEvent->slug : null;
    }

    public function build()
    {
        return $this
            ->subject('Sua produção está pronta para vender')
            ->view('emails.producer-sales-ready');
    }
}
