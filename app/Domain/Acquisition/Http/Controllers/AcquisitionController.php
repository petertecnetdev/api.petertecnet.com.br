<?php

namespace App\Domain\Acquisition\Http\Controllers;

use App\Domain\Acquisition\Services\AcquisitionAccess;
use App\Http\Controllers\Controller;
use App\Mail\AcquisitionReferralMail;
use App\Models\AcquisitionReferral;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventAcquisitionCommission;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class AcquisitionController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AcquisitionAccess $access,
    ) {}

    public function context(Request $request)
    {
        $user = $this->access->assertAgent($request->user());

        return response()->json([
            'is_agent' => true,
            'role' => 'acquisition_agent',
            'application' => $this->context->application()->only(['id', 'name', 'slug', 'url']),
            'agent' => $user->only(['id', 'first_name', 'last_name', 'email']),
        ]);
    }

    public function dashboard(Request $request)
    {
        $agent = $this->access->assertAgent($request->user());
        $appId = $this->context->id();

        $referrals = AcquisitionReferral::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agent->id);

        $rules = EventAcquisitionCommission::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agent->id)
            ->with(['event:id,production_id,title,slug,start_date,end_date,is_published', 'event.production:id,name,slug'])
            ->orderByDesc('id')
            ->get();

        $eventIds = $rules->pluck('event_id')->unique()->values();
        $sales = $eventIds->isEmpty()
            ? collect()
            : CommerceOrder::query()
                ->where('app_id', $appId)
                ->whereIn('event_id', $eventIds)
                ->where('status', 'paid')
                ->selectRaw('event_id, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as gross_sales')
                ->groupBy('event_id')
                ->get()
                ->keyBy('event_id');

        $commissionRows = $rules->map(function (EventAcquisitionCommission $rule) use ($sales) {
            $sale = $sales->get($rule->event_id);
            $gross = round((float) ($sale?->gross_sales ?? 0), 2);
            $percentage = (float) $rule->percentage;

            return [
                'id' => $rule->id,
                'event_id' => $rule->event_id,
                'event' => $rule->event,
                'percentage' => $percentage,
                'basis' => $rule->basis,
                'orders_count' => (int) ($sale?->orders_count ?? 0),
                'gross_sales' => $gross,
                'commission_amount' => round($gross * ($percentage / 100), 2),
            ];
        });

        $recent = (clone $referrals)
            ->with(['referredUser:id,first_name,last_name,email,email_verified_at', 'production:id,name,slug,is_published'])
            ->withCount('commissions')
            ->latest()
            ->limit(12)
            ->get();

        return response()->json([
            'metrics' => [
                'referrals_total' => (clone $referrals)->count(),
                'referrals_pending' => (clone $referrals)->where('status', 'pending')->count(),
                'referrals_accepted' => (clone $referrals)->where('status', 'accepted')->count(),
                'productions_total' => (clone $referrals)->whereNotNull('production_id')->count(),
                'events_total' => $rules->count(),
                'paid_orders' => $commissionRows->sum('orders_count'),
                'gross_sales' => round($commissionRows->sum('gross_sales'), 2),
                'commission_amount' => round($commissionRows->sum('commission_amount'), 2),
                'conversion_rate' => (clone $referrals)->count() > 0
                    ? round(((clone $referrals)->where('status', 'accepted')->count() / (clone $referrals)->count()) * 100, 1)
                    : 0,
            ],
            'recent_referrals' => $recent,
            'commissions' => $commissionRows,
        ]);
    }

    public function referrals(Request $request)
    {
        $agent = $this->access->assertAgent($request->user());
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'expired', 'revoked'])],
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $agent->id)
            ->with([
                'referredUser:id,first_name,last_name,email,email_verified_at',
                'production:id,name,slug,is_published',
                'commissions.event:id,production_id,title,slug,start_date,end_date,is_published',
            ])
            ->latest();

        if (! empty($data['status'])) $query->where('status', $data['status']);
        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($q) => $q
                ->where('email', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhereHas('production', fn ($p) => $p->where('name', 'like', "%{$term}%")));
        }

        return response()->json([
            'referrals' => $query->paginate($data['per_page'] ?? 25)->appends($request->query()),
        ]);
    }

    public function onboard(Request $request)
    {
        $agent = $this->access->assertAgent($request->user());
        $this->normalizeOnboardingInput($request);
        $data = $request->validate($this->onboardingRules());
        $appId = $this->context->id();
        $appSlug = $this->context->slug();
        $email = strtolower(trim($data['user']['email']));
        $rawToken = Str::random(64);
        $rawCode = $this->newCode(8);
        $createdNewUser = false;

        $result = DB::transaction(function () use ($data, $agent, $appId, $appSlug, $email, $rawToken, $rawCode, &$createdNewUser) {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user) {
                $createdNewUser = true;
                $user = User::create([
                    'first_name' => trim($data['user']['first_name']),
                    'last_name' => trim((string) ($data['user']['last_name'] ?? '')) ?: null,
                    'email' => $email,
                    'password' => Hash::make(Str::random(48)),
                    'user_name' => $this->uniqueUsername($data['user']['first_name']),
                    'is_producer' => true,
                ]);
            }

            $alreadyReferred = AcquisitionReferral::query()
                ->where('application_id', $appId)
                ->where('email', $email)
                ->whereIn('status', ['pending', 'accepted'])
                ->exists();
            abort_if($alreadyReferred, 409, 'Este usuário já possui um onboarding de aquisição ativo nesta aplicação.');

            $existingMembership = DB::table('application_user')
                ->where('application_id', $appId)
                ->where('user_id', $user->id)
                ->first();

            if (! $existingMembership) {
                $user->applications()->attach($appId, [
                    'role' => 'producer',
                    'status' => $createdNewUser ? 'pending' : 'active',
                    'metadata' => json_encode([
                        'acquisition_agent_id' => $agent->id,
                        'invited_at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $createdNewUser ? null : now(),
                ]);
            }

            $productionData = $data['production'];
            $productionData['app_id'] = $appId;
            $productionData['app_slug'] = $appSlug;
            $productionData['user_id'] = $user->id;
            $productionData['slug'] = $this->uniqueProductionSlug($productionData['name']);
            $productionData['is_published'] = true;
            $productionData['is_cancelled'] = false;
            $production = Production::create($productionData);

            $referral = AcquisitionReferral::create([
                'application_id' => $appId,
                'agent_user_id' => $agent->id,
                'referred_user_id' => $user->id,
                'production_id' => $production->id,
                'email' => $email,
                'name' => trim($data['user']['first_name'].' '.($data['user']['last_name'] ?? '')),
                'token_hash' => hash('sha256', $rawToken),
                'activation_code_hash' => Hash::make($rawCode),
                'requires_password' => $createdNewUser,
                'status' => 'pending',
                'expires_at' => now()->addDays(2),
                'last_sent_at' => now(),
                'metadata' => ['created_by_agent' => true],
            ]);

            $events = collect();
            foreach ($data['events'] as $eventInput) {
                $commissionPercentage = (float) $eventInput['commission_percentage'];
                $tickets = $eventInput['tickets'];
                unset($eventInput['commission_percentage'], $eventInput['tickets']);

                $eventInput['app_id'] = $appId;
                $eventInput['app_slug'] = $appSlug;
                $eventInput['production_id'] = $production->id;
                $eventInput['slug'] = $this->uniqueEventSlug($eventInput['title']);
                $eventInput['is_published'] = false;
                $eventInput['is_cancelled'] = false;
                $eventInput['event_format'] = $eventInput['event_format'] ?? 'in_person';
                $event = Event::create($eventInput);

                foreach ($tickets as $ticketInput) {
                    $price = round((float) $ticketInput['price'], 2);
                    Ticket::create([
                        'app_id' => $appId,
                        'app_slug' => $appSlug,
                        'event_id' => $event->id,
                        'name' => trim($ticketInput['name']),
                        'ticket_type' => $price > 0 ? ($ticketInput['ticket_type'] ?? 'standard') : 'courtesy',
                        'type' => $price > 0 ? 'paid' : 'courtesy',
                        'price' => $price,
                        'quantity' => (int) $ticketInput['quantity'],
                        'limit_date' => $ticketInput['limit_date'] ?? null,
                        'description' => $ticketInput['description'] ?? null,
                    ]);
                }

                EventAcquisitionCommission::create([
                    'application_id' => $appId,
                    'agent_user_id' => $agent->id,
                    'referral_id' => $referral->id,
                    'event_id' => $event->id,
                    'percentage' => $commissionPercentage,
                    'basis' => 'gross_sales',
                ]);

                $events->push($event->fresh()->load('tickets'));
            }

            return compact('user', 'production', 'referral', 'events');
        }, 3);

        $mailSent = $this->sendReferralMail($result['referral'], $result['user'], $result['production'], $result['events'], $rawToken, $rawCode);

        return response()->json([
            'message' => $mailSent
                ? 'Produtor, produção, eventos e ingressos cadastrados. O convite foi enviado por e-mail.'
                : 'Cadastro concluído, mas o e-mail não pôde ser enviado agora. Use a ação de reenviar convite.',
            'email_sent' => $mailSent,
            'referral' => $result['referral']->fresh()->load(['referredUser:id,first_name,last_name,email', 'production:id,name,slug', 'commissions.event:id,title,slug']),
        ], 201);
    }

    public function resend(Request $request, int $referralId)
    {
        $agent = $this->access->assertAgent($request->user());
        $referral = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $agent->id)
            ->with(['referredUser', 'production.events.tickets'])
            ->findOrFail($referralId);

        abort_if(in_array($referral->status, ['accepted', 'revoked'], true), 422, 'Este convite não pode mais ser reenviado.');

        $rawToken = Str::random(64);
        $rawCode = $this->newCode(8);
        $referral->forceFill([
            'token_hash' => hash('sha256', $rawToken),
            'activation_code_hash' => Hash::make($rawCode),
            'status' => 'pending',
            'expires_at' => now()->addDays(2),
            'last_sent_at' => now(),
        ])->save();

        $sent = $this->sendReferralMail(
            $referral,
            $referral->referredUser,
            $referral->production,
            $referral->production?->events ?? collect(),
            $rawToken,
            $rawCode,
        );

        return response()->json([
            'message' => $sent ? 'Convite reenviado.' : 'Não foi possível enviar o e-mail neste momento.',
            'email_sent' => $sent,
        ], $sent ? 200 : 503);
    }

    public function updateCommission(Request $request, int $eventId)
    {
        $agent = $this->access->assertAgent($request->user());
        $data = $request->validate(['percentage' => 'required|numeric|min:0|max:100']);

        $rule = EventAcquisitionCommission::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $agent->id)
            ->where('event_id', $eventId)
            ->firstOrFail();

        $rule->update(['percentage' => round((float) $data['percentage'], 2)]);

        return response()->json([
            'message' => 'Comissão do evento atualizada.',
            'commission' => $rule->fresh()->load('event:id,title,slug'),
        ]);
    }

    public function publicReferral(string $token)
    {
        $referral = $this->findPublicReferral($token);
        if ($referral->status === 'pending' && now()->greaterThan($referral->expires_at)) {
            $referral->update(['status' => 'expired']);
        }
        abort_unless($referral->status === 'pending', 410, 'Este convite não está mais disponível.');

        return response()->json([
            'referral' => [
                'email' => $referral->email,
                'name' => $referral->name,
                'requires_password' => $referral->requires_password,
                'expires_at' => $referral->expires_at,
                'production' => $referral->production?->only(['id', 'name', 'slug']),
                'events' => $referral->production?->events()->select(['id','production_id','title','slug','start_date','end_date'])->orderBy('start_date')->get() ?? [],
            ],
            'application' => $this->context->application()->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|min:40|max:128',
            'activation_code' => 'required|string|min:6|max:16',
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => 'nullable|string|same:password',
        ]);

        $referral = $this->findPublicReferral($data['token']);
        abort_unless($referral->status === 'pending', 410, 'Este convite não está mais disponível.');
        abort_if(now()->greaterThan($referral->expires_at), 422, 'Código expirado. Solicite um novo convite ao agente.');
        abort_unless(Hash::check($data['activation_code'], $referral->activation_code_hash), 422, 'Código de ativação inválido.');
        if ($referral->requires_password) {
            abort_unless(! empty($data['password']), 422, 'Crie uma senha para ativar sua conta.');
        }

        DB::transaction(function () use ($referral, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($referral->referred_user_id);
            $changes = ['email_verified_at' => $user->email_verified_at ?: now()];
            if (! empty($data['password'])) $changes['password'] = Hash::make($data['password']);
            $user->forceFill($changes)->save();

            $membership = DB::table('application_user')
                ->where('application_id', $this->context->id())
                ->where('user_id', $user->id)
                ->first();

            if ($membership && $membership->status !== 'active') {
                $user->applications()->updateExistingPivot($this->context->id(), [
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $referral->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
                'activation_code_hash' => Hash::make(Str::random(48)),
                'token_hash' => hash('sha256', Str::random(64)),
            ])->save();
        }, 3);

        return response()->json([
            'message' => 'E-mail confirmado e acesso ativado. Você já pode entrar na aplicação.',
            'login_url' => rtrim((string) $this->context->application()->url, '/').'/login',
        ]);
    }

    private function findPublicReferral(string $token): AcquisitionReferral
    {
        return AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('token_hash', hash('sha256', $token))
            ->with('production')
            ->firstOrFail();
    }

    private function sendReferralMail(AcquisitionReferral $referral, User $user, Production $production, $events, string $rawToken, string $rawCode): bool
    {
        try {
            Mail::to($referral->email)->send(new AcquisitionReferralMail(
                user: $user,
                application: $this->context->application(),
                production: $production,
                events: collect($events),
                code: $rawCode,
                token: $rawToken,
                requiresPassword: $referral->requires_password,
            ));
            return true;
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar convite de aquisição.', [
                'referral_id' => $referral->id,
                'application_id' => $this->context->id(),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function onboardingRules(): array
    {
        return [
            'user.first_name' => ['required','string','min:2','max:100'],
            'user.last_name' => ['nullable','string','max:100'],
            'user.email' => ['required','email','max:255'],
            'production.name' => ['required','string','min:2','max:255'],
            'production.fantasy' => ['nullable','string','max:255'],
            'production.cnpj' => ['nullable','regex:/^\d{14}$/'],
            'production.phone' => ['nullable','string','max:30'],
            'production.description' => ['nullable','string','max:10000'],
            'production.city' => ['nullable','string','max:120'],
            'production.uf' => ['nullable','string','size:2'],
            'production.address' => ['nullable','string','max:255'],
            'production.website_url' => ['nullable','url:http,https','max:2048'],
            'production.instagram_url' => ['nullable','url:http,https','max:2048'],
            'events' => ['required','array','min:1','max:20'],
            'events.*.title' => ['required','string','min:2','max:255'],
            'events.*.description' => ['required','string','max:50000'],
            'events.*.start_date' => ['required','date','after:now'],
            'events.*.end_date' => ['required','date','after:events.*.start_date'],
            'events.*.event_format' => ['nullable', Rule::in(['in_person','online','hybrid'])],
            'events.*.address' => ['nullable','string','max:500'],
            'events.*.venue' => ['nullable','string','max:255'],
            'events.*.city' => ['nullable','string','max:120'],
            'events.*.uf' => ['nullable','string','size:2'],
            'events.*.online_url' => ['nullable','url:http,https','max:2048'],
            'events.*.commission_percentage' => ['required','numeric','min:0','max:100'],
            'events.*.tickets' => ['required','array','min:1','max:50'],
            'events.*.tickets.*.name' => ['required','string','max:255'],
            'events.*.tickets.*.quantity' => ['required','integer','min:1','max:100000'],
            'events.*.tickets.*.price' => ['required','numeric','min:0','max:999999.99'],
            'events.*.tickets.*.ticket_type' => ['nullable', Rule::in(['courtesy','standard','vip','premium','student','half','full'])],
            'events.*.tickets.*.limit_date' => ['nullable','date'],
            'events.*.tickets.*.description' => ['nullable','string','max:5000'],
        ];
    }

    private function normalizeOnboardingInput(Request $request): void
    {
        $payload = $request->all();
        if (isset($payload['user']['email'])) $payload['user']['email'] = strtolower(trim((string) $payload['user']['email']));
        if (isset($payload['production']['cnpj'])) $payload['production']['cnpj'] = preg_replace('/\D+/', '', (string) $payload['production']['cnpj']);
        if (isset($payload['production']['uf'])) $payload['production']['uf'] = strtoupper(trim((string) $payload['production']['uf']));
        foreach (($payload['events'] ?? []) as $index => $event) {
            if (isset($event['uf'])) $payload['events'][$index]['uf'] = strtoupper(trim((string) $event['uf']));
        }
        $request->replace($payload);
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';
        do { $username = $base.'-'.Str::lower(Str::random(6)); }
        while (User::query()->where('user_name', $username)->exists());
        return $username;
    }

    private function uniqueProductionSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'producao'; $slug = $base; $i = 2;
        while (Production::withTrashed()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function uniqueEventSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evento'; $slug = $base; $i = 2;
        while (Event::query()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
        for ($i = 0; $i < $length; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $code;
    }
}
