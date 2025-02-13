<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Barbershop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewBarberMail extends Mailable
{
    use Queueable, SerializesModels;

    public $barbershop;
    public $barber;

    /**
     * Create a new message instance.
     *
     * @param Barbershop $barbershop
     * @param User $barber
     */
    public function __construct(Barbershop $barbershop, User $barber)
    {
        $this->barbershop = $barbershop;
        $this->barber = $barber;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Você foi adicionado à barbearia!')
                    ->view('emails.new_barber')
                    ->with([
                        'barbershop' => $this->barbershop,
                        'barber' => $this->barber,
                    ]);
    }
}
