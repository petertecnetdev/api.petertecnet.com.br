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
        $this->activationUrl = $this->appUrl.'/invite-complete?'.http_build_query([
            'email' => $user->email,
            'code' => $code,
        ]);
    }

    public function build()
    {
        return $this
            ->subject("Sua nova conta no {$this->appName}")
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
