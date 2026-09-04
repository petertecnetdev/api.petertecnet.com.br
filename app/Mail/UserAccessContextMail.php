<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\User;
use App\Services\UserOnboardingCommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserAccessContextMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $context;
    public string $appUrl;

    public function __construct(User $user, Application $application, ?Establishment $establishment = null)
    {
        $this->context = app(UserOnboardingCommunicationService::class)
            ->buildContext($user, $application, $establishment);

        $url = trim((string) $application->url);
        $this->appUrl = filter_var($url, FILTER_VALIDATE_URL)
            ? $url
            : 'https://petertecnet.com.br';
    }

    public function build()
    {
        return $this
            ->subject($this->context['subject'])
            ->view('emails.user-access-context')
            ->with([
                'context' => $this->context,
                'appUrl' => $this->appUrl,
            ]);
    }
}
