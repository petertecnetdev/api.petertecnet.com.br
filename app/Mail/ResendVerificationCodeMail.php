<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\User;

class ResendVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $verificationCode;
    public $user;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($verificationCode, User $user)
    {
        $this->verificationCode = $verificationCode;
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.resend_verification_code')
                    ->subject('Reenvio do Código de Verificação')
                    ->with([
                        'verificationCode' => $this->verificationCode,
                        'user' => $this->user,
                    ]);
    }
}
