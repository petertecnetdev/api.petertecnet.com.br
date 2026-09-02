<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $appId;
    public $appName;
    public $appUrl;
    public $activationUrl;

    public function __construct($user, string $code, string $appName, ?string $appUrl, ?int $appId = null)
    {
        $this->user = $user;
        $this->code = $code;

        $application = $appId ? Application::query()->find($appId) : null;
        $this->appId = $application?->id ?? $appId;
        $this->appName = trim((string) ($application?->name ?? $appName)) ?: 'Plataforma Peter Tecnet';

        $candidateUrl = rtrim(trim((string) ($application?->url ?? $appUrl)), '/');
        $this->appUrl = filter_var($candidateUrl, FILTER_VALIDATE_URL)
            ? $candidateUrl
            : rtrim((string) config('app.frontend_url', 'https://petertecnet.com.br'), '/');

        $frontendUrl = rtrim((string) config('app.frontend_url', 'https://petertecnet.com.br'), '/');
        if (! filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
            $frontendUrl = 'https://petertecnet.com.br';
        }

        $query = [
            'email' => $user->email,
            'app_name' => $this->appName,
        ];

        if ($this->appId) {
            $query['app_id'] = $this->appId;
        }

        $this->activationUrl = $frontendUrl.'/invite-complete?'.http_build_query($query);
    }

    public function build()
    {
        return $this
            ->subject("Ative seu acesso ao {$this->appName}")
            ->view('emails.invite-user')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'appId' => $this->appId,
                'appName' => $this->appName,
                'appUrl' => $this->appUrl,
                'activationUrl' => $this->activationUrl,
            ]);
    }
}
