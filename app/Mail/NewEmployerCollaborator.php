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
        // Dados para a view
        $viewData = [
            'userName'          => $this->employer->user->first_name,
            'establishmentName' => $this->establishment->name,
            'role'              => $this->employer->role,
        ];

        // Renderiza o HTML da view e garante UTF-8
        $html = view('emails.new_employer_collaborator', $viewData)
                ->render();
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');

        // Assunto em UTF-8
        $subject = mb_convert_encoding(
            "Você foi adicionado(a) em {$viewData['establishmentName']}",
            'UTF-8',
            'auto'
        );

        // Log do HTML renderizado (para debug)
        \Log::info('Mail HTML NewEmployerCollaborator:', ['html' => substr($html,0,500)]);

        return $this
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
            // Define o corpo HTML já convertido
            ->html($html)
            ->withSwiftMessage(function (Swift_Mime_SimpleMessage $message) {
                $message->setCharset('UTF-8');
                $message->getHeaders()
                        ->removeAll('Content-Type')
                        ->addTextHeader('Content-Type', 'text/html; charset=UTF-8');
                $message->setEncoder(
                    new Swift_Mime_ContentEncoder_PlainContentEncoder('8bit','UTF-8')
                );
            });
    }
}
