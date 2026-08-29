<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Swift_Mime_SimpleMessage;
use Swift_Mime_ContentEncoder_PlainContentEncoder;

class EmployerRemoved extends Mailable
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
        // O fluxo de desvinculação preserva o User antes de excluir o Employer
        // e o envia ao mailable. Também mantemos compatibilidade caso algum
        // fluxo antigo ainda envie o próprio Employer.
        $collaborator = $this->employer->user ?? $this->employer;
        $role = $this->employer->role ?? 'colaborador';

        $viewData = [
            'userName'          => $collaborator->first_name ?? 'Colaborador',
            'establishmentName' => $this->establishment->name,
            'role'              => $role,
        ];

        $html = view('emails.employer_removed', $viewData)->render();
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');

        $subject = mb_convert_encoding(
            "Você não faz mais parte da equipe de {$viewData['establishmentName']}",
            'UTF-8',
            'auto'
        );

        \Log::info('Mail HTML EmployerRemoved:', ['html' => substr($html,0,500)]);

        return $this
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
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
