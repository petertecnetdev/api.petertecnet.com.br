<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public User $user;
    public string $resetUrl;
    public array $brand;

    public function __construct(string $code, User $user)
    {
        $this->code = $code;
        $this->user = $user;

        $application = $this->resolveApplication($user);
        $baseUrl = rtrim(trim((string) ($application?->url ?? '')), '/');
        if (! $this->isPublicHttpsUrl($baseUrl)) {
            $baseUrl = 'https://petertecnet.com.br';
        }

        $branding = is_array($application?->branding) ? $application->branding : [];
        $this->brand = [
            'name' => $application?->name ?: 'Peter Tecnet',
            'slug' => $application?->slug ?: 'peter-tecnet',
            'logo' => $application?->logo ?: 'https://petertecnet.com.br/petertecnetlogo.png',
            'primary_color' => $branding['primary_color'] ?? $branding['primary'] ?? '#00BFFF',
            'background_color' => $branding['background_color'] ?? $branding['background'] ?? '#0B1F30',
            'surface_color' => $branding['surface_color'] ?? $branding['surface'] ?? '#132A3A',
        ];

        $this->resetUrl = $baseUrl.'/account/password/reset?'.http_build_query([
            'email' => $user->email,
        ]);
    }

    public function build()
    {
        return $this->subject('Redefinição de senha - '.$this->brand['name'])
            ->view('emails.reset_password')
            ->with([
                'code' => $this->code,
                'userName' => $this->user->first_name,
                'resetUrl' => $this->resetUrl,
                'brand' => $this->brand,
            ]);
    }

    private function resolveApplication(User $user): ?Application
    {
        $explicitId = data_get($user->extra_info, 'primary_application_id')
            ?? data_get($user->extra_info, 'primary_app_id');

        if ($explicitId) {
            $explicit = Application::query()->active()->find($explicitId);
            if ($explicit) {
                return $explicit;
            }
        }

        $linked = $user->applications()->active()->get();
        $pivotPrimary = $linked->first(function (Application $application) {
            $metadata = $application->pivot?->metadata;
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            return (bool) (($metadata['primary'] ?? false) || ($metadata['is_primary'] ?? false));
        });

        if ($pivotPrimary) {
            return $pivotPrimary;
        }

        $mostActiveAppId = Interaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('app_id')
            ->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('app_id, COUNT(*) as interactions_count, MAX(created_at) as last_activity_at')
            ->groupBy('app_id')
            ->orderByDesc('interactions_count')
            ->orderByDesc('last_activity_at')
            ->value('app_id');

        if ($mostActiveAppId) {
            $mostActive = Application::query()->active()->find($mostActiveAppId);
            if ($mostActive) {
                return $mostActive;
            }
        }

        return $linked
            ->sortByDesc(fn (Application $application) => $application->pivot?->updated_at?->getTimestamp() ?? 0)
            ->first()
            ?: Application::query()->where('slug', 'peter-tecnet')->first();
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
