<?php

// app/Mail/VerificationCodeMail.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $verificationCode;

    /**
     * Cria uma nova instância da mensagem.
     *
     * @param  string  $verificationCode
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($verificationCode, $user)
    {
        $this->verificationCode = $verificationCode;
        $this->user = $user;
    }

    /**
     * Constrói a mensagem.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.verification-code')
                    ->subject('Código de Verificação para Registro na Cutinapp');
    }
}
