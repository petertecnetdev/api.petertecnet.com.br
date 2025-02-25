<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\User;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $verificationCode;
    public $user;
    public $password;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($verificationCode, User $user, $password)
    {
        $this->verificationCode = $verificationCode;
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Bem-vindo à Peter Tecnet')
                    ->markdown('emails.welcome')
                    ->with([
                        'verificationCode' => $this->verificationCode,
                        'user' => $this->user,
                        'password' => $this->password,
                    ]);
    }
}
