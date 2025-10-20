<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Swift_Mime_SimpleMessage;
use Swift_Mime_ContentEncoder_PlainContentEncoder;

class OwnerNotifiedEmployerDetached extends Mailable
{
    use Queueable, SerializesModels;

    public $establishment;
    public $employer;

    public function __construct($establishment, $employer)
    {
        $this->establishment = $establishment;
        $this->employer = $employer;
    }

    public function build()
    {
        $viewData = [
            'collaboratorName'  => $this->employer->user->first_name,
            'collaboratorEmail' => $this->employer->user->email,
            'establishmentName' => $this->establishment->name,
            'role'              => $this->employer->role,
        ];

        $html = view('emails.owner_notified_employer_detached', $viewData)
                ->render();
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');

        $subject = mb_convert_encoding(
            "Colaborador desvinculado do estabelecimento {$viewData['establishmentName']}",
            'UTF-8',
            'auto'
        );

        \Log::info('Mail HTML OwnerNotifiedEmployerDetached:', ['html' => substr($html, 0, 500)]);

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
