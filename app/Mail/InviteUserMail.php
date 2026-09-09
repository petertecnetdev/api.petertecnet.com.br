<?php

namespace App\Mail;

use App\Models\Application;
use App\Services\UserOnboardingCommunicationService;
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
    public array $context;

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
        $application ??= Application::query()
            ->where('name', $appName)
            ->when($appUrl, fn ($query) => $query->orWhere('url', $appUrl))
            ->first();

        $this->appId = $application?->id ?? $appId;
        $this->appName = trim((string) ($application?->name ?? $appName)) ?: 'Plataforma Peter Tecnet';

        $targetUrl = rtrim(trim((string) ($application?->url ?? $appUrl)), '/');
        $this->appUrl = $this->isPublicHttpsUrl($targetUrl)
            ? $targetUrl
            : 'https://petertecnet.com.br';

        $this->context = $application
            ? app(UserOnboardingCommunicationService::class)->buildContext($user, $application)
            : [
                'subject' => "Ative seu acesso ao {$this->appName}",
                'title' => "Seu acesso ao {$this->appName} está pronto",
                'intro' => 'Preparamos seu cadastro. Confirme seu e-mail e crie sua própria senha para liberar o acesso.',
                'features' => ['Acessar os recursos liberados para o seu perfil.'],
                'relationship' => 'Usuário',
                'establishment_name' => null,
            ];

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

            // Code-based invitations are completed inside the destination application.
            // Token-based invitations above continue to use the central activation flow.
            $this->activationUrl = $this->appUrl.'/invite-complete?'.http_build_query($query);
        }
    }

    public function build()
    {
        return $this
            ->subject($this->context['subject'] ?? "Ative seu acesso ao {$this->appName}")
            ->view('emails.invite-user')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'appId' => $this->appId,
                'appName' => $this->appName,
                'appUrl' => $this->appUrl,
                'activationUrl' => $this->activationUrl,
                'context' => $this->context,
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
