<?php 
namespace App\Mail;

use App\Models\Establishment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployerAddedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $establishment;
    public $employer;
    public $role;

    public function __construct(Establishment $establishment, User $employer, $role = null)
    {
        $this->establishment = $establishment;
        $this->employer = $employer;
        $this->role = $role;
    }

    public function build()
    {
        return $this->view('emails.employer_added')
            ->with([
                'establishment' => $this->establishment,
                'employer' => $this->employer,
                'role' => $this->role,
            ]);
    }
}
