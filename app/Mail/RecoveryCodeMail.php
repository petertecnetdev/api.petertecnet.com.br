<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RecoveryCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $resetCode; // Variável para armazenar o código de recuperação de senha

    /**
     * Cria uma nova instância da classe.
     *
     * @param string $resetCode O código de recuperação de senha
     */
    public function __construct($resetCode)
    {
        $this->resetCode = $resetCode;
    }

    /**
     * Constrói a mensagem de email.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Código de Recuperação de Senha') // Assunto do email
                    ->markdown('emails.reset_password'); // Arquivo de template do email
    }
}
