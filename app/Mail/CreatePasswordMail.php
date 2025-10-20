<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CreatePasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $user;
    public $link;

    /**
     * Create a new message instance.
     *
     * @param string $code
     * @param User $user
     * @param string $link
     */
    public function __construct(string $code, User $user, string $link)
    {
        $this->code = $code;
        $this->user = $user;
        $this->link = $link;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Bem-vindo! Crie sua senha de acesso')
                    ->view('emails.create_password')
                    ->with([
                        'code' => $this->code,
                        'userName' => $this->user->first_name,
                        'link' => $this->link,
                    ]);
    }
}
