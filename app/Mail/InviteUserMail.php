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

    public function __construct(
        $user,
        string $code,
        string $appName,
        ?string $appUrl,
        ?int $appId = null,
        ?string $invitationToken = null
    ) {
        $this->user = $user;
        $this->code = $code;

        $application = $appId ? Application::query()->find($appId) : null;
        $this->appId = $application?->id ?? $appId;
        $this->appName = trim((string) ($application?->name ?? $appName)) ?: 'Plataforma Peter Tecnet';

        $targetUrl = rtrim(trim((string) ($application?->url ?? $appUrl)), '/');
        $this->appUrl = $this->isPublicHttpsUrl($targetUrl)
            ? $targetUrl
            : 'https://petertecnet.com.br';

        $centralApplication = Application::query()
            ->where('slug', 'peter-tecnet')
            ->first();

        $activationBaseUrl = rtrim(trim((string) ($centralApplication?->url ?? '')), '/');
        if (! $this->isPublicHttpsUrl($activationBaseUrl)) {
            $activationBaseUrl = 'https://petertecnet.com.br';
        }

        if ($invitationToken) {
            $this->activationUrl = $activationBaseUrl.'/account/activate?'.http_build_query([
                'token' => $invitationToken,
            ]);
        } else {
            $query = [
                'email' => $user->email,
                'app_name' => $this->appName,
            ];

            if ($this->appId) {
                $query['app_id'] = $this->appId;
            }

            $this->activationUrl = $activationBaseUrl.'/invite-complete?'.http_build_query($query);
        }
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

    private function isPublicHttpsUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
