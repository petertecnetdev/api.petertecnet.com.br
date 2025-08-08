<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Swift_Mime_SimpleMessage;

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
        return $this
            ->from('no-reply@seusite.com.br', 'Sua Aplicação')
            ->subject("Você foi adicionado(a) em ".$this->establishment->name)
            ->view('emails.new_employer_collaborator')
            ->with([
                'userName'           => $this->employer->user->first_name,
                'establishmentName'  => $this->establishment->name,
                'role'               => $this->employer->role,
            ])
            ->withSwiftMessage(function (Swift_Mime_SimpleMessage $message) {
                // força UTF-8 no cabeçalho e corpo
                $message->setCharset('UTF-8');
                $message->setEncoder(
                  new \Swift_Mime_ContentEncoder_PlainContentEncoder('8bit', 'UTF-8')
                );
            });
    }
}
