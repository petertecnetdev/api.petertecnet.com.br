<?php

namespace App\Domain\Acquisition\Services;

use App\Mail\AcquisitionReferralMail;
use App\Models\AcquisitionReferral;
use App\Models\Event;
use App\Models\EventAcquisitionCommission;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class AcquisitionOnboardingService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AcquisitionAccess $access,
    ) {}

    public function normalize(array $payload): array
    {
        if (isset($payload['user']['email'])) {
            $payload['user']['email'] = strtolower(trim((string) $payload['user']['email']));
        }
        if (isset($payload['production']['cnpj'])) {
            $payload['production']['cnpj'] = preg_replace('/\D+/', '', (string) $payload['production']['cnpj']);
            if ($payload['production']['cnpj'] === '') $payload['production']['cnpj'] = null;
        }
        if (isset($payload['production']['uf'])) {
            $payload['production']['uf'] = strtoupper(trim((string) $payload['production']['uf']));
            if ($payload['production']['uf'] === '') $payload['production']['uf'] = null;
        }
        foreach (($payload['events'] ?? []) as $index => $event) {
            if (isset($event['uf'])) {
                $payload['events'][$index]['uf'] = strtoupper(trim((string) $event['uf']));
                if ($payload['events'][$index]['uf'] === '') $payload['events'][$index]['uf'] = null;
            }
        }

        return $payload;
    }

    public function onboard(?User $user, array $data): array
    {
        $agent = $this->access->assertAgent($user);
        $appId = $this->context->id();
        $appSlug = $this->context->slug();
        $email = strtolower(trim((string) $data['user']['email']));
        $rawToken = Str::random(64);
        $rawCode = $this->newCode(8);
        $createdNewUser = false;

        $result = DB::transaction(function () use ($data, $agent, $appId, $appSlug, $email, $rawToken, $rawCode, &$createdNewUser) {
            $referredUser = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $referredUser) {
                $createdNewUser = true;
                $referredUser = User::create([
                    'first_name' => trim((string) $data['user']['first_name']),
                    'last_name' => trim((string) ($data['user']['last_name'] ?? '')) ?: null,
                    'email' => $email,
                    'password' => Hash::make(Str::random(48)),
                    'user_name' => $this->uniqueUsername((string) $data['user']['first_name']),
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
                ->where('user_id', $referredUser->id)
                ->first();

            if (! $existingMembership) {
                $referredUser->applications()->attach($appId, [
                    'role' => 'producer',
                    'status' => 'pending',
                    'metadata' => json_encode([
                        'acquisition_agent_id' => $agent->id,
                        'invited_at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                    'joined_at' => null,
                ]);
            }

            $productionData = $data['production'];
            $productionData['app_id'] = $appId;
            $productionData['app_slug'] = $appSlug;
            $productionData['user_id'] = $referredUser->id;
            $productionData['slug'] = $this->uniqueProductionSlug((string) $productionData['name']);
            $productionData['is_published'] = false;
            $productionData['is_cancelled'] = false;
            $production = Production::create($productionData);

            $referral = AcquisitionReferral::create([
                'application_id' => $appId,
                'agent_user_id' => $agent->id,
                'referred_user_id' => $referredUser->id,
                'production_id' => $production->id,
                'email' => $email,
                'name' => trim((string) $data['user']['first_name'].' '.(string) ($data['user']['last_name'] ?? '')),
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
                $eventInput['slug'] = $this->uniqueEventSlug((string) $eventInput['title']);
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
                        'name' => trim((string) $ticketInput['name']),
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

            return compact('referredUser', 'production', 'referral', 'events');
        }, 3);

        $mailSent = $this->sendReferralMail(
            $result['referral'],
            $result['referredUser'],
            $result['production'],
            $result['events'],
            $rawToken,
            $rawCode,
        );

        return [
            'message' => $mailSent
                ? 'Produtor, produção, eventos e ingressos cadastrados. O convite foi enviado por e-mail.'
                : 'Cadastro concluído, mas o e-mail não pôde ser enviado agora. Use a ação de reenviar convite.',
            'email_sent' => $mailSent,
            'referral' => $result['referral']->fresh()->load([
                'referredUser:id,first_name,last_name,email',
                'production:id,name,slug',
                'commissions.event:id,title,slug',
            ]),
        ];
    }

    public function resend(?User $user, int $referralId): array
    {
        $agent = $this->access->assertAgent($user);
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

        return [
            'message' => $sent ? 'Convite reenviado.' : 'Não foi possível enviar o e-mail neste momento.',
            'email_sent' => $sent,
        ];
    }

    public function publicReferral(string $token): array
    {
        $referral = $this->findPublicReferral($token);
        if ($referral->status === 'pending' && now()->greaterThan($referral->expires_at)) {
            $referral->update(['status' => 'expired']);
        }
        abort_unless($referral->status === 'pending', 410, 'Este convite não está mais disponível.');

        return [
            'referral' => [
                'email' => $referral->email,
                'name' => $referral->name,
                'requires_password' => $referral->requires_password,
                'expires_at' => $referral->expires_at,
                'production' => $referral->production?->only(['id', 'name', 'slug']),
                'events' => $referral->production?->events()
                    ->select(['id','production_id','title','slug','start_date','end_date'])
                    ->orderBy('start_date')
                    ->get() ?? [],
            ],
            'application' => $this->context->application()->only(['id', 'name', 'slug', 'url']),
        ];
    }

    public function activate(array $data): array
    {
        $referral = $this->findPublicReferral((string) $data['token']);
        abort_unless($referral->status === 'pending', 410, 'Este convite não está mais disponível.');
        abort_if(now()->greaterThan($referral->expires_at), 422, 'Código expirado. Solicite um novo convite ao agente.');
        abort_unless(Hash::check((string) $data['activation_code'], $referral->activation_code_hash), 422, 'Código de ativação inválido.');
        if ($referral->requires_password) {
            abort_unless(! empty($data['password']), 422, 'Crie uma senha para ativar sua conta.');
        }

        DB::transaction(function () use ($referral, $data) {
            $referredUser = User::query()->lockForUpdate()->findOrFail($referral->referred_user_id);
            $changes = ['email_verified_at' => $referredUser->email_verified_at ?: now()];
            if (! empty($data['password'])) $changes['password'] = Hash::make((string) $data['password']);
            $referredUser->forceFill($changes)->save();

            $membership = DB::table('application_user')
                ->where('application_id', $this->context->id())
                ->where('user_id', $referredUser->id)
                ->first();

            if ($membership && $membership->status !== 'active') {
                $referredUser->applications()->updateExistingPivot($this->context->id(), [
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            if ($referral->production_id) {
                Production::query()
                    ->where('app_id', $this->context->id())
                    ->whereKey($referral->production_id)
                    ->update(['is_published' => true]);
            }

            $referral->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
                'activation_code_hash' => Hash::make(Str::random(48)),
                'token_hash' => hash('sha256', Str::random(64)),
            ])->save();
        }, 3);

        $base = rtrim((string) $this->context->application()->url, '/');

        return [
            'message' => 'E-mail confirmado e acesso ativado. Você já pode entrar na aplicação.',
            'login_url' => ($base !== '' ? $base : 'https://petertecnet.com.br').'/login',
        ];
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

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';
        do { $username = $base.'-'.Str::lower(Str::random(6)); }
        while (User::query()->where('user_name', $username)->exists());
        return $username;
    }

    private function uniqueProductionSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'producao';
        $slug = $base;
        $i = 2;
        while (Production::withTrashed()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function uniqueEventSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evento';
        $slug = $base;
        $i = 2;
        while (Event::query()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }
}
