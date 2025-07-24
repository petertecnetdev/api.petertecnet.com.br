<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentUpdateClient extends Mailable
{
    use Queueable, SerializesModels;

    public Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function build()
    {
        $status     = $this->appointment->status;
        $subjectMap = [
            'confirmed' => 'Seu agendamento foi confirmado',
            'cancelled' => 'Seu agendamento foi cancelado',
        ];
        return $this
            ->subject($subjectMap[$status] ?? 'Atualização no seu agendamento')
            ->view('emails.appointment_update_client')
            ->with([
                'appointment' => $this->appointment,
                'status'      => $status,
            ]);
    }
}
