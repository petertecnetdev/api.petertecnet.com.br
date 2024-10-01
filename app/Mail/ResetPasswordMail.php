<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $user;

    /**
     * Create a new message instance.
     *
     * @param string $code
     * @param User $user
     */
    public function __construct(string $code, User $user)
    {
        $this->code = $code;
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Redefinição de Senha')
                    ->view('emails.reset_password')
                    ->with([
                        'code' => $this->code,
                        'userName' => $this->user->name,
                    ]);
    }
}
