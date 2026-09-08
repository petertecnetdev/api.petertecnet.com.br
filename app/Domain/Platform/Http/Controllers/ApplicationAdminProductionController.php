<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Engagement\Services\EstablishmentEngagementReportService;
use App\Http\Controllers\Controller;
use App\Mail\EstablishmentEngagementSummaryMail;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApplicationAdminProductionController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EstablishmentEngagementReportService $engagementReport,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:published,draft,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Production::query()
            ->where('app_id', $this->context->id())
            ->with('user:id,first_name,last_name,email')
            ->withCount(['events', 'employers']);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('fantasy', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$term.'%')));
        }

        match ($data['status'] ?? null) {
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            default => null,
        };

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function update(Request $request, int $production): JsonResponse
    {
        $model = $this->production($production);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $model->fill($data)->save();

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $model->fresh()->load('user:id,first_name,last_name,email')->loadCount(['events', 'employers']),
        ]);
    }

    public function engagementPreview(Request $request, int $production): JsonResponse
    {
        $model = $this->production($production)->load('user:id,first_name,last_name,email');
        [$recipientEmail, $recipientName] = $this->recipient($model);
        $application = $this->application();
        $report = $this->engagementReport->generate($model, $application);
        $communicationId = (string) Str::uuid();

        $html = view('emails.establishment-engagement-summary', [
            'application' => $application,
            'establishment' => $model,
            'recipientName' => $recipientName,
            'report' => $report,
            'communicationId' => $communicationId,
            'ctas' => $report['ctas'],
            'trackingPixelUrl' => null,
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'recipient' => ['name' => $recipientName, 'email' => $recipientEmail],
                'subject' => $report['subject'],
                'report' => $report,
                'html' => $html,
            ],
        ]);
    }

    public function sendEngagementEmail(Request $request, int $production): JsonResponse
    {
        $model = $this->production($production)->load('user:id,first_name,last_name,email');
        [$recipientEmail, $recipientName] = $this->recipient($model);
        $application = $this->application();
        $report = $this->engagementReport->generate($model, $application);
        $communicationId = (string) Str::uuid();

        $trackedCtas = collect($report['ctas'])->map(function (array $cta) use ($communicationId, $model, $application) {
            return array_merge($cta, [
                'url' => $this->trackedClickUrl(
                    communicationId: $communicationId,
                    target: (string) $cta['url'],
                    cta: (string) $cta['key'],
                    entityId: (int) $model->id,
                    appId: (int) $application->id,
                ),
            ]);
        })->all();

        $trackingPixelUrl = URL::temporarySignedRoute(
            'communications.track.open',
            now()->addDays(30),
            [
                'communicationId' => $communicationId,
                'entity_id' => (int) $model->id,
                'app_id' => (int) $application->id,
            ],
        );

        Mail::to($recipientEmail)->queue(new EstablishmentEngagementSummaryMail(
            application: $application,
            establishment: $model,
            recipientName: $recipientName,
            report: $report,
            communicationId: $communicationId,
            ctas: $trackedCtas,
            trackingPixelUrl: $trackingPixelUrl,
        ));

        EcosystemAuditLog::query()->create([
            'user_id' => $request->user('api')?->id ?? $request->user()?->id,
            'action' => 'communication.engagement.sent',
            'entity_type' => 'establishment',
            'entity_id' => $model->id,
            'before' => null,
            'after' => [
                'communication_id' => $communicationId,
                'app_id' => $application->id,
                'recipient' => $recipientEmail,
                'subject' => $report['subject'],
                'metrics' => $report['metrics'],
                'cta_keys' => collect($report['ctas'])->pluck('key')->values()->all(),
            ],
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Resumo enviado ao produtor com sucesso.',
            'data' => [
                'communication_id' => $communicationId,
                'recipient' => $recipientEmail,
                'subject' => $report['subject'],
            ],
        ], 202);
    }

    private function production(int $id): Production
    {
        return Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($id);
    }

    private function application(): Application
    {
        return Application::query()->findOrFail($this->context->id());
    }

    private function recipient(Production $production): array
    {
        $email = trim((string) ($production->user?->email ?: $production->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'recipient' => ['A produção não possui um e-mail válido para receber o resumo.'],
            ]);
        }

        $userName = trim(implode(' ', array_filter([
            $production->user?->first_name,
            $production->user?->last_name,
        ])));

        return [$email, $userName !== '' ? $userName : (string) $production->name];
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
