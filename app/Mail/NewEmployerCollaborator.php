<?php

namespace App\Mail;

use App\Models\Establishment;
use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewEmployerCollaborator extends Mailable
{
    use Queueable, SerializesModels;

    public Establishment $establishment;
    public Employer $employer;

    public function __construct(Establishment $establishment, Employer $employer)
    {
        $this->establishment = $establishment;
        $this->employer = $employer;
    }

    public function build(): self
    {
        return $this
            ->subject("Você foi adicionado como colaborador em {$this->establishment->name}")
            ->view('emails.new_employer_collaborator')
            ->with([
                'establishmentName' => $this->establishment->name,
                'role'              => $this->employer->role,
                'userName'          => $this->employer->user->first_name,
            ]);
    }
}
