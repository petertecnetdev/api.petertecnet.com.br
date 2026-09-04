<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class AcquisitionReferralMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $activationUrl;

    public function __construct(
        public User $user,
        public Application $application,
        public Production $production,
        public Collection $events,
        public string $code,
        string $token,
        public bool $requiresPassword,
    ) {
        $base = rtrim((string) $application->url, '/');
        $this->activationUrl = $base.'/agent/activate?'.http_build_query([
            'ref' => $token,
            'email' => $user->email,
        ]);
    }

    public function build()
    {
        return $this
            ->subject('Sua produção e seus eventos já estão preparados')
            ->view('emails.acquisition-referral');
    }
}
