<?php

namespace App\Domain\Engagement\Services;

use App\Mail\EstablishmentEngagementSummaryMail;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EstablishmentEngagementCommunicationService
{
    public function __construct(
        private readonly EstablishmentEngagementReportService $reportService,
    ) {
    }

    public function preview(int $applicationId, int $establishmentId): array
    {
        $application = $this->application($applicationId);
        $establishment = $this->establishment($applicationId, $establishmentId);
        [$recipientEmail, $recipientName] = $this->recipient($establishment);
        $report = $this->reportService->generate($establishment, $application);
        $communicationId = (string) Str::uuid();

        $html = view('emails.establishment-engagement-summary', [
            'application' => $application,
            'establishment' => $establishment,
            'recipientName' => $recipientName,
            'report' => $report,
            'communicationId' => $communicationId,
            'ctas' => $report['ctas'],
            'trackingPixelUrl' => null,
        ])->render();

        return [
            'recipient' => ['name' => $recipientName, 'email' => $recipientEmail],
            'subject' => $report['subject'],
            'report' => $report,
            'html' => $html,
        ];
    }

    public function send(
        int $applicationId,
        int $establishmentId,
        ?int $actorId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $application = $this->application($applicationId);
        $establishment = $this->establishment($applicationId, $establishmentId);
        [$recipientEmail, $recipientName] = $this->recipient($establishment);
        $report = $this->reportService->generate($establishment, $application);
        $communicationId = (string) Str::uuid();

        $trackedCtas = collect($report['ctas'])->map(function (array $cta) use ($communicationId, $establishment, $application) {
            return array_merge($cta, [
                'url' => $this->trackedClickUrl(
                    communicationId: $communicationId,
                    target: (string) $cta['url'],
                    cta: (string) $cta['key'],
                    entityId: (int) $establishment->id,
                    appId: (int) $application->id,
                ),
            ]);
        })->all();

        $trackingPixelUrl = URL::temporarySignedRoute(
            'communications.track.open',
            now()->addDays(30),
            [
                'communicationId' => $communicationId,
                'entity_id' => (int) $establishment->id,
                'app_id' => (int) $application->id,
            ],
        );

        Mail::to($recipientEmail)->queue(new EstablishmentEngagementSummaryMail(
            application: $application,
            establishment: $establishment,
            recipientName: $recipientName,
            report: $report,
            communicationId: $communicationId,
            ctas: $trackedCtas,
            trackingPixelUrl: $trackingPixelUrl,
        ));

        EcosystemAuditLog::query()->create([
            'user_id' => $actorId,
            'action' => 'communication.engagement.sent',
            'entity_type' => 'establishment',
            'entity_id' => $establishment->id,
            'before' => null,
            'after' => [
                'communication_id' => $communicationId,
                'app_id' => $application->id,
                'recipient' => $recipientEmail,
                'subject' => $report['subject'],
                'metrics' => $report['metrics'],
                'cta_keys' => collect($report['ctas'])->pluck('key')->values()->all(),
            ],
            'ip' => $ip,
            'user_agent' => substr((string) $userAgent, 0, 1000),
        ]);

        return [
            'communication_id' => $communicationId,
            'recipient' => $recipientEmail,
            'subject' => $report['subject'],
        ];
    }

    private function establishment(int $applicationId, int $establishmentId): Establishment
    {
        return Establishment::query()
            ->where('app_id', $applicationId)
            ->where('category', 'production')
            ->with('user:id,first_name,last_name,email')
            ->findOrFail($establishmentId);
    }

    private function application(int $applicationId): Application
    {
        return Application::query()->findOrFail($applicationId);
    }

    private function recipient(Establishment $establishment): array
    {
        $email = trim((string) ($establishment->user?->email ?: $establishment->email ?: $establishment->contact_email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'recipient' => ['A produção não possui um e-mail válido para receber o resumo.'],
            ]);
        }

        $userName = trim(implode(' ', array_filter([
            $establishment->user?->first_name,
            $establishment->user?->last_name,
        ])));

        return [$email, $userName !== '' ? $userName : (string) $establishment->name];
    }

    private function trackedClickUrl(string $communicationId, string $target, string $cta, int $entityId, int $appId): string
    {
        return URL::temporarySignedRoute(
            'communications.track.click',
            now()->addDays(30),
            [
                'communicationId' => $communicationId,
                'target' => rtrim(strtr(base64_encode($target), '+/', '-_'), '='),
                'cta' => $cta,
                'entity_id' => $entityId,
                'app_id' => $appId,
            ],
        );
    }
}
