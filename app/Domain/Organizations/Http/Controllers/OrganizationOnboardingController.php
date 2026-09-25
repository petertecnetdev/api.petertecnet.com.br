<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Services\FinancialIdentityService;
use App\Services\MerchantPaymentAccountService;
use App\Services\ProducerAgreementService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class OrganizationOnboardingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
        private readonly MerchantPaymentAccountService $paymentAccounts,
        private readonly FinancialIdentityService $identity,
    ) {}

    public function initiate(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->isAdmin($actor), 403, 'Somente um administrador pode iniciar um onboarding assistido.');

        $data = $request->validate([
            'producer.first_name' => 'required|string|min:2|max:100',
            'producer.email' => 'required|email|max:255',
            'producer.phone' => 'nullable|string|max:30',
            'organization.name' => 'required|string|min:2|max:255',
            'organization.cnpj' => 'nullable|string|max:18',
            'organization.description' => 'nullable|string|max:10000',
            'organization.city' => 'nullable|string|max:120',
            'organization.uf' => 'nullable|string|size:2',
            'organization.address' => 'nullable|string|max:255',
            'organization.instagram_url' => 'nullable|string|max:2048',
            'event.title' => 'nullable|string|min:2|max:255',
            'event.description' => 'nullable|string|max:50000',
            'event.start_date' => 'nullable|required_with:event.title|date|after:now',
            'event.end_date' => 'nullable|required_with:event.title|date|after:event.start_date',
            'event.venue' => 'nullable|string|max:255',
            'event.city' => 'nullable|string|max:120',
            'event.uf' => 'nullable|string|size:2',
            'events' => 'nullable|array|max:12',
            'events.*.title' => 'required|string|min:2|max:255',
            'events.*.description' => 'nullable|string|max:50000',
            'events.*.start_date' => 'required|date|after:now',
            'events.*.end_date' => 'nullable|date|after:events.*.start_date',
            'events.*.venue' => 'nullable|string|max:255',
            'events.*.city' => 'nullable|string|max:120',
            'events.*.uf' => 'nullable|string|size:2',
            'send_email' => 'sometimes|boolean',
        ]);

        $result = DB::transaction(function () use ($data, $actor) {
            $email = strtolower(trim($data['producer']['email']));
            $owner = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $owner) {
                $owner = User::create([
                    'first_name' => trim($data['producer']['first_name']),
                    'email' => $email,
                    'phone' => $data['producer']['phone'] ?? null,
                    'password' => Hash::make(Str::random(48)),
                    'user_name' => $this->uniqueUsername($data['producer']['first_name'], $email),
                    'is_producer' => true,
                ]);
            } else {
                $owner->forceFill([
                    'is_producer' => true,
                    'phone' => $owner->phone ?: ($data['producer']['phone'] ?? null),
                ])->save();
            }

            $owner->applications()->syncWithoutDetaching([
                $this->context->id() => [
                    'role' => 'producer',
                    'status' => 'active',
                    'joined_at' => now(),
                ],
            ]);

            $organizationData = $data['organization'];
            $organization = Production::create([
                'app_id' => $this->context->id(),
                'app_slug' => $this->context->slug(),
                'user_id' => $owner->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'name' => trim($organizationData['name']),
                'fantasy' => trim($organizationData['name']),
                'slug' => $this->uniqueOrganizationSlug($organizationData['name']),
                'cnpj' => isset($organizationData['cnpj']) ? preg_replace('/\D+/', '', $organizationData['cnpj']) : null,
                'description' => $organizationData['description'] ?? null,
                'city' => $organizationData['city'] ?? null,
                'uf' => isset($organizationData['uf']) ? strtoupper($organizationData['uf']) : null,
                'address' => $organizationData['address'] ?? null,
                'instagram_url' => $organizationData['instagram_url'] ?? null,
                'is_published' => false,
                'is_approved' => false,
                'is_cancelled' => false,
            ]);

            $eventPayloads = collect($data['events'] ?? []);
            if (! empty($data['event']['title'])) {
                $eventPayloads->prepend($data['event']);
            }

            $events = collect();
            foreach ($eventPayloads as $eventData) {
                $events->push(Event::create([
                    'app_id' => $this->context->id(),
                    'app_slug' => $this->context->slug(),
                    'production_id' => $organization->id,
                    'title' => trim($eventData['title']),
                    'slug' => $this->uniqueEventSlug($eventData['title']),
                    'description' => $eventData['description'] ?? 'Evento criado durante o onboarding assistido. Revise as informações antes de publicar.',
                    'start_date' => $eventData['start_date'] ?? null,
                    'end_date' => $eventData['end_date'] ?? null,
                    'venue' => $eventData['venue'] ?? null,
                    'city' => $eventData['city'] ?? $organization->city,
                    'uf' => isset($eventData['uf']) ? strtoupper($eventData['uf']) : $organization->uf,
                    'is_published' => false,
                    'is_cancelled' => false,
                    'is_private' => false,
                ]));
            }
            $event = $events->first();

            DB::table('organization_onboardings')->updateOrInsert(
                [
                    'app_id' => $this->context->id(),
                    'establishment_id' => $organization->id,
                ],
                [
                    'owner_user_id' => $owner->id,
                    'assisted_by_user_id' => $actor->id,
                    'initial_event_id' => $event?->id,
                    'status' => 'awaiting_owner',
                    'authorized_email' => $email,
                    'authorized_at' => now(),
                    'metadata' => json_encode(['source' => 'assisted_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return compact('owner', 'organization', 'event', 'events');
        }, 3);

        $shouldSendEmail = $request->boolean('send_email', true);
        $handoffSent = $shouldSendEmail
            ? $this->sendHandoffEmail($result['organization'], $result['owner'], $result['event'], $result['events'])
            : false;

        $message = count($result['events'])
            ? 'Onboarding assistido criado. A produção e os eventos iniciais ficaram em rascunho para o produtor assumir a operação.'
            : 'Onboarding assistido criado. A produção ficou pronta para o produtor assumir a operação.';
        if ($shouldSendEmail && ! $handoffSent) {
            $message .= ' O cadastro foi salvo, mas o e-mail de entrega falhou e deve ser reenviado pelo Admin Center.';
        }

        return response()->json([
            'message' => $message,
            'handoff_sent' => $handoffSent,
            'events_created' => $result['events']->count(),
            'onboarding' => $this->statusPayload($result['organization'], $result['owner']),
        ], 201);
    }

    public function show(Request $request, int $organizationId)
    {
        $organization = $this->accessibleOrganization($request, $organizationId);
        $owner = User::query()->findOrFail($organization->user_id);

        return response()->json([
            'onboarding' => $this->statusPayload($organization, $owner),
        ]);
    }

    public function mine(Request $request)
    {
        $organizations = Production::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'onboardings' => $organizations
                ->map(fn (Production $organization) => $this->statusPayload($organization, $request->user()))
                ->values(),
        ]);
    }

    public function index(Request $request)
    {
        abort_unless($this->isAdmin($request->user()), 403, 'Somente um administrador pode acompanhar os onboardings assistidos.');

        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|in:awaiting_owner,awaiting_agreement,awaiting_payout,ready_to_sell',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $onboardingIds = DB::table('organization_onboardings')
            ->where('app_id', $this->context->id())
            ->pluck('establishment_id');

        $query = Production::query()
            ->where('app_id', $this->context->id())
            ->whereIn('id', $onboardingIds)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('id');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('email', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                    );
            });
        }

        $paginator = $query->paginate($data['per_page'] ?? 30)->appends($request->query());
        $rows = $paginator->getCollection()
            ->map(fn (Production $organization) => $this->statusPayload(
                $organization,
                $organization->user ?: User::query()->findOrFail($organization->user_id)
            ));

        if (! empty($data['status'])) {
            $rows = $rows->where('status', $data['status'])->values();
        }

        $paginator->setCollection($rows);

        return response()->json(['onboardings' => $paginator]);
    }

    public function resendHandoff(Request $request, int $organizationId)
    {
        $organization = $this->accessibleOrganization($request, $organizationId);
        abort_unless($this->isAdmin($request->user()), 403, 'Somente um administrador pode reenviar a entrega assistida.');

        $owner = User::query()->findOrFail($organization->user_id);
        $row = DB::table('organization_onboardings')
            ->where('app_id', $this->context->id())
            ->where('establishment_id', $organization->id)
            ->first();
        $event = $row?->initial_event_id ? Event::query()->find($row->initial_event_id) : null;
        $events = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $organization->id)
            ->orderBy('start_date')
            ->limit(20)
            ->get();

        abort_unless(
            $this->sendHandoffEmail($organization, $owner, $event, $events),
            502,
            'Não foi possível enviar o e-mail de entrega agora.'
        );

        return response()->json([
            'message' => 'E-mail de entrega do onboarding reenviado.',
            'handoff_sent' => true,
        ]);
    }

    private function statusPayload(Production $organization, User $owner): array
    {
        $agreementSigned = DB::table('contract_acceptances')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organization->id)
            ->where('contract_version', $this->agreements->version())
            ->exists();

        $payoutReady = DB::table('financial_payout_destinations as destination')
            ->join('financial_beneficiaries as beneficiary', 'beneficiary.id', '=', 'destination.beneficiary_id')
            ->where('destination.source_type', 'production')
            ->where('destination.source_id', $organization->id)
            ->whereIn('destination.status', ['active', 'cooling'])
            ->whereNotNull('destination.verified_at')
            ->where('beneficiary.user_id', $owner->id)
            ->where('beneficiary.status', 'verified')
            ->exists();

        $firstEvent = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $organization->id)
            ->orderBy('id')
            ->first();

        $payment = $this->paymentAccounts->readiness($organization->id);
        $record = DB::table('organization_onboardings')
            ->where('app_id', $this->context->id())
            ->where('establishment_id', $organization->id)
            ->first();

        $identity = $this->identity->overview($owner);
        $verification = $identity['verification'] ?? null;
        $identityProfileReady = (bool) ($identity['beneficiary'] ?? null);
        $documentUploaded = (bool) data_get($verification, 'document_front_uploaded', false);
        $selfieRequired = (bool) ($identity['selfie_document_required'] ?? true);
        $selfieUploaded = ! $selfieRequired || (bool) data_get($verification, 'selfie_document_uploaded', false);
        $livenessRequired = (bool) ($identity['liveness_required'] ?? false);
        $livenessReady = ! $livenessRequired || (
            data_get($verification, 'liveness_status') === 'passed'
            && data_get($verification, 'face_match_status') === 'passed'
        );

        $steps = [
            'account' => true,
            'organization' => true,
            'initial_event' => (bool) $firstEvent,
            'agreement' => $agreementSigned,
            'identity' => $identityProfileReady,
            'document' => $documentUploaded,
            'selfie_document' => $selfieUploaded,
            'liveness' => $livenessReady,
            'payout' => $payoutReady,
        ];
        $completed = count(array_filter($steps));
        $salesReady = $agreementSigned && $payoutReady && (bool) ($payment['available'] ?? false);

        $status = $salesReady
            ? 'ready_to_sell'
            : ($agreementSigned ? 'awaiting_payout' : 'awaiting_agreement');

        if ($record && ($record->status !== $status || ($salesReady && ! $record->completed_at))) {
            DB::table('organization_onboardings')
                ->where('id', $record->id)
                ->update([
                    'status' => $status,
                    'completed_at' => $salesReady ? ($record->completed_at ?: now()) : null,
                    'updated_at' => now(),
                ]);
        }

        return [
            'organization' => $organization->only(['id', 'name', 'slug', 'city', 'uf']),
            'owner' => ['id' => $owner->id, 'first_name' => $owner->first_name, 'email' => $owner->email],
            'initial_event' => $firstEvent?->only(['id', 'title', 'slug', 'start_date', 'is_published']),
            'steps' => $steps,
            'completed_steps' => $completed,
            'total_steps' => count($steps),
            'progress' => (int) round(($completed / count($steps)) * 100),
            'status' => $status,
            'sales_ready' => $salesReady,
            'payment_available' => (bool) ($payment['available'] ?? false),
            'payment_methods' => array_values($payment['methods'] ?? []),
            'payout_ready' => $payoutReady,
            'agreement_signed' => $agreementSigned,
            'assisted' => (bool) $record,
            'authorized_at' => $record?->authorized_at,
            'handoff_sent_at' => $record?->handoff_sent_at,
            'completed_at' => $record?->completed_at,
        ];
    }

    private function accessibleOrganization(Request $request, int $organizationId): Production
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($organizationId);

        abort_unless(
            $this->isAdmin($request->user()) || (int) $organization->user_id === (int) $request->user()->id,
            403,
            'Você não pode acessar o onboarding desta organização.'
        );

        return $organization;
    }

    private function sendHandoffEmail(Production $organization, User $owner, ?Event $event, iterable $events = []): bool
    {
        $application = $this->context->application();
        $frontend = 'https://' . $this->context->slug() . '.petertecnet.com.br';
        $onboardingUrl = $frontend . '/producer/onboarding?productionId=' . $organization->id;
        $agreementUrl = $frontend . '/producer/contracts?productionId=' . $organization->id;
        $financeUrl = $frontend . '/producer/finance?production=' . $organization->id . '&focus=activation';
        $events = collect($events);
        if ($events->isEmpty() && $event) $events = collect([$event]);

        try {
            Mail::send('emails.organization-onboarding-handoff', compact(
                'application',
                'organization',
                'owner',
                'event',
                'events',
                'onboardingUrl',
                'agreementUrl',
                'financeUrl'
            ), function ($message) use ($owner, $organization, $application) {
                $message->to($owner->email)
                    ->subject('Sua produção ' . $organization->name . ' está pronta para você — ' . $application->name);
            });

            DB::table('organization_onboardings')
                ->where('app_id', $this->context->id())
                ->where('establishment_id', $organization->id)
                ->update(['handoff_sent_at' => now(), 'updated_at' => now()]);

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
    }

    private function isAdmin(?User $user): bool
    {
        return (bool) ($user && (
            $user->hasProfile('Administrador')
            || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com'
        ));
    }

    private function uniqueUsername(string $name, string $email): string
    {
        $base = Str::slug($name, '_') ?: Str::before($email, '@') ?: 'producer';
        $base = Str::limit($base, 40, '');
        $candidate = $base;
        $suffix = 2;

        while (User::query()->where('user_name', $candidate)->exists()) {
            $candidate = $base . '_' . $suffix++;
        }

        return $candidate;
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organizacao';
        $slug = $base;
        $suffix = 2;
        while (Production::query()->where('slug', $slug)->exists()) $slug = $base . '-' . $suffix++;
        return $slug;
    }

    private function uniqueEventSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evento';
        $slug = $base;
        $suffix = 2;
        while (Event::query()->where('slug', $slug)->exists()) $slug = $base . '-' . $suffix++;
        return $slug;
    }
}
