<?php

namespace App\Domain\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublicInquiryController extends Controller
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:255'],
            'need' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:10000'],
            'service_slug' => ['nullable', 'string', 'max:160'],
            'budget' => ['nullable', 'string', 'max:120'],
            'urgency' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:80'],
            'source_url' => ['nullable', 'url', 'max:1000'],
            'source_path' => ['nullable', 'string', 'max:500'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['file', 'max:8192', 'mimes:pdf,doc,docx,txt,png,jpg,jpeg,webp'],
        ]);

        $applicationId = $this->applicationContext->id();
        $establishment = Establishment::query()
            ->forApplication($applicationId)
            ->when(
                ! empty($data['establishment_id']),
                fn ($query) => $query->whereKey((int) $data['establishment_id'])
            )
            ->whereNotNull('user_id')
            ->orderByDesc('is_featured')
            ->orderByDesc('is_published')
            ->orderBy('id')
            ->firstOrFail();

        $scope = [
            'app_id' => $applicationId,
            'establishment_id' => (int) $establishment->id,
            'owner_user_id' => (int) $establishment->user_id,
        ];

        $attachmentPaths = [];
        foreach ($request->file('attachments', []) as $file) {
            $attachmentPaths[] = Storage::disk('local')->putFile(
                'crm-inquiries/' . $applicationId . '/' . now()->format('Y/m'),
                $file
            );
        }

        $contactId = $this->findContact($scope, $data['email'] ?? null, $data['phone'] ?? null);
        $contactNotes = $this->contactNotes($data);

        if ($contactId) {
            DB::table('crm_contacts')->where('id', $contactId)->where($scope)->update([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'source' => $data['source'] ?? 'website',
                'notes' => $contactNotes,
                'updated_at' => now(),
            ]);
        } else {
            $contactId = DB::table('crm_contacts')->insertGetId([
                ...$scope,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'document' => null,
                'source' => $data['source'] ?? 'website',
                'notes' => $contactNotes,
                'status' => 'lead',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $opportunityId = DB::table('crm_opportunities')->insertGetId([
            ...$scope,
            'contact_id' => $contactId,
            'title' => $this->opportunityTitle($data),
            'stage' => 'new',
            'value' => 0,
            'probability' => 20,
            'notes' => $this->opportunityNotes($data, $attachmentPaths),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Solicitação recebida com sucesso.',
            'data' => [
                'contact_id' => $contactId,
                'opportunity_id' => $opportunityId,
                'reference' => 'WEB-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            ],
        ], 201);
    }

    private function findContact(array $scope, ?string $email, ?string $phone): ?int
    {
        if (! $email && ! $phone) return null;

        $query = DB::table('crm_contacts')->where($scope);
        $query->where(function ($builder) use ($email, $phone) {
            if ($email) $builder->where('email', $email);
            if ($phone) {
                $email
                    ? $builder->orWhere('phone', $phone)
                    : $builder->where('phone', $phone);
            }
        });

        return $query->value('id');
    }

    private function contactNotes(array $data): string
    {
        $company = $data['company'] ?? null;
        $sourceUrl = $data['source_url'] ?? null;

        return collect([
            $company ? 'Empresa: ' . $company : null,
            'Origem: ' . ($data['source'] ?? 'website'),
            $sourceUrl ? 'URL: ' . $sourceUrl : null,
        ])->filter()->join("\n");
    }

    private function opportunityTitle(array $data): string
    {
        $service = trim((string) ($data['need'] ?? 'Novo projeto'));
        return mb_substr('Site Peter Tecnet · ' . $service, 0, 255);
    }

    private function opportunityNotes(array $data, array $attachments): string
    {
        $serviceSlug = $data['service_slug'] ?? null;
        $budget = $data['budget'] ?? null;
        $urgency = $data['urgency'] ?? null;
        $company = $data['company'] ?? null;
        $sourcePath = $data['source_path'] ?? null;
        $sourceUrl = $data['source_url'] ?? null;

        return collect([
            $data['message'] ?? null,
            $serviceSlug ? 'Serviço: ' . $serviceSlug : null,
            $budget ? 'Orçamento informado: ' . $budget : null,
            $urgency ? 'Urgência: ' . $urgency : null,
            $company ? 'Empresa: ' . $company : null,
            $sourcePath ? 'Página de origem: ' . $sourcePath : null,
            $sourceUrl ? 'URL de origem: ' . $sourceUrl : null,
            $attachments ? 'Anexos internos: ' . implode(', ', $attachments) : null,
        ])->filter()->join("\n\n");
    }
}
