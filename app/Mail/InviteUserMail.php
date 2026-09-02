<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $appName;
    public $appUrl;
    public $activationUrl;

    public function __construct($user, string $code, string $appName, ?string $appUrl)
    {
        $this->user = $user;
        $this->code = $code;
        $this->appName = trim($appName) ?: 'Plataforma Peter Tecnet';

        $candidateUrl = rtrim(trim((string) $appUrl), '/');
        $this->appUrl = filter_var($candidateUrl, FILTER_VALIDATE_URL)
            ? $candidateUrl
            : rtrim((string) config('app.frontend_url', 'https://petertecnet.com.br'), '/');

        $frontendUrl = rtrim((string) config('app.frontend_url', 'https://petertecnet.com.br'), '/');
        if (! filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
            $frontendUrl = 'https://petertecnet.com.br';
        }

        $this->activationUrl = $frontendUrl.'/invite-complete?'.http_build_query([
            'email' => $user->email,
            'code' => $code,
            'app_url' => $this->appUrl,
            'app_name' => $this->appName,
        ]);
    }

    public function build()
    {
        return $this
            ->subject("Seu acesso ao {$this->appName} está pronto")
            ->view('emails.invite-user')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'appName' => $this->appName,
                'appUrl' => $this->appUrl,
                'activationUrl' => $this->activationUrl,
            ]);
    }
}
