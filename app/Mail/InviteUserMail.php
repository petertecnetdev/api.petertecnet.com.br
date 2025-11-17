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

    public function __construct($user, $code, $appId)
    {
        $this->user = $user;
        $this->code = $code;

        $this->appName = match ((int) $appId) {
            2 => 'Rasoio',
            3 => 'Cutinapp',
            default => 'Plataforma Peter Tecnet',
        };
    }

    public function build()
    {
        return $this
            ->subject("Convite para acessar {$this->appName}")
            ->view('emails.invite-user')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'appName' => $this->appName,
            ]);
    }
}
