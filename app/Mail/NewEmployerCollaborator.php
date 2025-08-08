<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Swift_Mime_SimpleMessage;
use Swift_Mime_ContentEncoder_PlainContentEncoder;

class NewEmployerCollaborator extends Mailable
{
    use Queueable, SerializesModels;

    public $establishment;
    public $employer;

    public function __construct($establishment, $employer)
    {
        $this->establishment = $establishment;
        $this->employer      = $employer;
    }

    public function build()
    {
        // Garante que cada string está em UTF-8 puro
        $userName          = mb_convert_encoding($this->employer->user->first_name,    'UTF-8', 'auto');
        $establishmentName = mb_convert_encoding($this->establishment->name,           'UTF-8', 'auto');
        $role              = mb_convert_encoding($this->employer->role,               'UTF-8', 'auto');
        $subject           = mb_convert_encoding("Você foi adicionado(a) em {$establishmentName}", 'UTF-8', 'auto');

        return $this
            ->from('no-reply@seusite.com.br', 'Sua Aplicação')
            ->subject($subject)
            ->view('emails.new_employer_collaborator')
            ->with(compact('userName','establishmentName','role'))
            ->withSwiftMessage(function (Swift_Mime_SimpleMessage $message) {
                // Força UTF-8 nos headers e corpo
                $message->setCharset('UTF-8');
                $message->getHeaders()
                        ->removeAll('Content-Type')
                        ->addTextHeader('Content-Type', 'text/html; charset=UTF-8');
                // Usa encoder 8bit com UTF-8
                $message->setEncoder(new Swift_Mime_ContentEncoder_PlainContentEncoder('8bit','UTF-8'));
            });
    }
}
