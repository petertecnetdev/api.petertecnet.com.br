<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteNewUserToEmployer extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $establishment;

    /**
     * Create a new message instance.
     *
     * @param $user
     * @param $code
     * @param $establishment
     */
    public function __construct($user, $code, $establishment)
    {
        $this->user = $user;
        $this->code = $code;
        $this->establishment = $establishment;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $url = url("/complete-registration?code={$this->code}");

        return $this->subject("Você foi convidado para colaborar no estabelecimento {$this->establishment->name}")
                    ->view('emails.invite_new_user_to_employer')
                    ->with([
                        'userName' => $this->user->first_name,
                        'establishmentName' => $this->establishment->name,
                        'code' => $this->code,
                        'url' => $url,
                    ]);
    }
}
