<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $user;
    public $resetUrl;

    public function __construct(string $code, User $user)
    {
        $this->code = $code;
        $this->user = $user;

        $centralApplication = Application::query()->where('slug', 'peter-tecnet')->first();
        $baseUrl = rtrim(trim((string) ($centralApplication?->url ?? '')), '/');
        if (! $this->isPublicHttpsUrl($baseUrl)) {
            $baseUrl = 'https://petertecnet.com.br';
        }

        $this->resetUrl = $baseUrl.'/account/password/reset?'.http_build_query([
            'email' => $user->email,
        ]);
    }

    public function build()
    {
        return $this->subject('Redefinição de Senha')
            ->view('emails.reset_password')
            ->with([
                'code' => $this->code,
                'userName' => $this->user->first_name,
                'resetUrl' => $this->resetUrl,
            ]);
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
