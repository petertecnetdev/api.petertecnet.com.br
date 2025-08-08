<?php

namespace App\Mail;

use App\Models\Establishment;
use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Swift_Mime_SimpleMessage;
use Swift_Mime_ContentEncoder_PlainContentEncoder;

class OwnerNotifiedNewCollaborator extends Mailable
{
    use Queueable, SerializesModels;

    public Establishment $establishment;
    public Employer      $employer;

    public function __construct(Establishment $establishment, Employer $employer)
    {
        $this->establishment = $establishment;
        $this->employer      = $employer;
    }

    public function build(): self
    {
        // Codifica tudo em UTF-8
        $establishmentName  = mb_convert_encoding($this->establishment->name,      'UTF-8', 'auto');
        $collaboratorName   = mb_convert_encoding($this->employer->user->first_name,'UTF-8', 'auto');
        $collaboratorEmail  = mb_convert_encoding($this->employer->user->email,     'UTF-8', 'auto');
        $role               = mb_convert_encoding($this->employer->role,            'UTF-8', 'auto');
        $subject            = mb_convert_encoding("Novo colaborador em {$establishmentName}", 'UTF-8', 'auto');

        return $this
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
            ->view('emails.owner_notified_new_collaborator')
            ->with(compact('establishmentName','collaboratorName','collaboratorEmail','role'))
            ->withSwiftMessage(function (Swift_Mime_SimpleMessage $message) {
                $message->setCharset('UTF-8');
                $message->getHeaders()
                        ->removeAll('Content-Type')
                        ->addTextHeader('Content-Type', 'text/html; charset=UTF-8');
                $message->setEncoder(new Swift_Mime_ContentEncoder_PlainContentEncoder('8bit','UTF-8'));
            });
    }
}
