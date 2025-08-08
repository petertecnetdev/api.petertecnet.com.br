<?php

namespace App\Mail;

use App\Models\Establishment;
use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OwnerNotifiedNewCollaborator extends Mailable
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
            ->subject("Novo colaborador adicionado em {$this->establishment->name}")
            ->view('emails.owner_notified_new_collaborator')
            ->with([
                'establishmentName'   => $this->establishment->name,
                'collaboratorName'    => $this->employer->user->first_name,
                'collaboratorEmail'   => $this->employer->user->email,
                'role'                => $this->employer->role,
            ]);
    }
}
