<?php

namespace App\Services;

use App\Mail\InviteUserMail;
use App\Mail\UserAccessContextMail;
use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserOnboardingCommunicationService
{
    public function buildContext(User $user, Application $application, ?Establishment $establishment = null): array
    {
        $employment = Employer::query()
            ->with('establishment')
            ->where('user_id', $user->id)
            ->whereHas('establishment', fn ($query) => $query->forApplication($application->id))
            ->latest('id')
            ->first();

        $establishment ??= $employment?->establishment;
        $establishment ??= Establishment::query()
            ->forApplication($application->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $linkedApplication = $user->applications()->whereKey($application->id)->first();
        $role = Str::lower(trim((string) (
            $employment?->role
            ?: $linkedApplication?->pivot?->role
            ?: $this->roleFromFlags($user)
            ?: 'member'
        )));

        $isOwner = $establishment && (int) $establishment->user_id === (int) $user->id;
        $establishmentName = $establishment?->fantasy ?: $establishment?->name;
        $domain = $this->applicationDomain($application);
        $context = $this->domainContext($domain, $user, $application, $role, (bool) $isOwner, $establishmentName);

        return array_merge($context, [
            'app_name' => $application->name,
            'app_url' => $application->url,
            'role' => $role,
            'relationship' => $this->relationshipLabel($role, (bool) $isOwner, $employment !== null),
            'establishment_name' => $establishmentName,
            'is_owner' => (bool) $isOwner,
            'has_establishment' => (bool) $establishment,
        ]);
    }

    public function notifyEstablishmentOwnership(Establishment $establishment, ?User $actor = null): array
    {
        $user = User::query()->find($establishment->user_id);
        if (! $user) {
            return [];
        }

        $applications = $establishment->applications()->get();
        if ($establishment->app_id) {
            $primary = Application::query()->find($establishment->app_id);
            if ($primary) {
                $applications->push($primary);
            }
        }

        $results = [];
        foreach ($applications->unique('id') as $application) {
            $this->ensureOwnerAccess($user, $application, $establishment, $actor);
            $results[] = $this->sendApplicationAccess(
                $user,
                $application,
                $actor,
                $establishment,
                'establishment_linked'
            );
        }

        return $results;
    }

    public function sendApplicationAccess(
        User $user,
        Application $application,
        ?User $actor = null,
        ?Establishment $establishment = null,
        string $source = 'admin_resend'
    ): array {
        $establishment ??= Establishment::query()
            ->forApplication($application->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($establishment && (int) $establishment->user_id === (int) $user->id) {
            $this->ensureOwnerAccess($user, $application, $establishment, $actor);
        }

        $access = $user->applications()->whereKey($application->id)->first();
        $requiresActivation = ! $user->email_verified_at || $access?->pivot?->status === 'pending';

        if ($requiresActivation) {
            if (! $access) {
                $user->applications()->syncWithoutDetaching([
                    $application->id => [
                        'status' => 'pending',
                        'role' => 'member',
                        'metadata' => json_encode([
                            'source' => $source,
                            'invited_by' => $actor?->id,
                            'invited_at' => now()->toIso8601String(),
                        ], JSON_UNESCAPED_UNICODE),
                        'joined_at' => null,
                    ],
                ]);
            }

            $issued = app(InvitationService::class)->issue($user, $application, $actor, [
                'source' => $source,
                'establishment_id' => $establishment?->id,
            ]);

            Mail::to($user->email)->send(new InviteUserMail(
                $user,
                $issued['code'],
                $application->name,
                $application->url,
                $application->id,
                $issued['token']
            ));

            return [
                'mode' => 'activation_invite',
                'application_id' => $application->id,
                'application' => $application->name,
                'expires_at' => $issued['invitation']->expires_at?->toIso8601String(),
            ];
        }

        Mail::to($user->email)->send(new UserAccessContextMail($user, $application, $establishment));

        return [
            'mode' => 'access_context',
            'application_id' => $application->id,
            'application' => $application->name,
        ];
    }

    private function ensureOwnerAccess(User $user, Application $application, Establishment $establishment, ?User $actor): void
    {
        $existing = $user->applications()->whereKey($application->id)->first();
        $metadata = $existing?->pivot?->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $status = $existing?->pivot?->status === 'active' || $user->email_verified_at ? 'active' : 'pending';
        $metadata = array_merge($metadata, [
            'source' => 'establishment_linked',
            'establishment_id' => $establishment->id,
            'linked_at' => now()->toIso8601String(),
            'linked_by' => $actor?->id,
        ]);

        $user->applications()->syncWithoutDetaching([
            $application->id => [
                'status' => $status,
                'role' => $existing?->pivot?->role ?: 'owner',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'joined_at' => $status === 'active' ? ($existing?->pivot?->joined_at ?: now()) : null,
            ],
        ]);
    }

    private function applicationDomain(Application $application): string
    {
        $slug = Str::lower((string) ($application->slug ?: Str::slug($application->name)));

        foreach ((array) config('onboarding.application_domains', []) as $mapping) {
            $needles = array_values(array_filter((array) ($mapping['contains'] ?? [])));
            if ($needles !== [] && Str::contains($slug, $needles)) {
                return (string) ($mapping['domain'] ?? 'generic');
            }
        }

        return 'generic';
    }

    private function domainContext(
        string $domain,
        User $user,
        Application $application,
        string $role,
        bool $isOwner,
        ?string $establishment
    ): array {
        return match ($domain) {
            'events' => $this->eventsContext($user, $application, $role, $isOwner, $establishment),
            'catalog' => $this->catalogContext($application, $role, $isOwner, $establishment),
            'restaurant' => $this->restaurantContext($application, $role, $isOwner, $establishment),
            'scheduling' => $this->schedulingContext($user, $application, $role, $isOwner, $establishment),
            'crm' => $this->crmContext($application, $establishment),
            'real_estate' => $this->realEstateContext($application, $establishment),
            'market' => $this->marketContext($application),
            default => $this->genericContext($application, $role, $isOwner, $establishment),
        };
    }

    private function eventsContext(User $user, Application $application, string $role, bool $isOwner, ?string $establishment): array
    {
        $name = $application->name;
        $producer = $isOwner || $user->is_producer || $this->roleMatches($role, ['producer', 'owner', 'manager']);
        if ($producer) {
            return [
                'subject' => "Sua produção no {$name} está pronta para ser gerenciada",
                'title' => $establishment ? "{$establishment} já está vinculado à sua conta" : "Seu acesso de produção no {$name} está pronto",
                'intro' => "Agora você tem autonomia para administrar sua operação dentro do {$name} e preparar seus próximos eventos.",
                'features' => [
                    'Cadastrar, editar e publicar eventos.',
                    'Criar itens, lotes e ingressos para os eventos.',
                    'Acompanhar participantes, vendas, cortesias e check-ins.',
                    'Gerenciar as informações e a presença pública da sua produção.',
                ],
            ];
        }

        if ($this->roleMatches($role, ['artist', 'artista'])) {
            return [
                'subject' => "Seu perfil de artista no {$name} está pronto",
                'title' => "Você já pode usar o {$name} como artista",
                'intro' => 'Seu acesso foi preparado para que você acompanhe sua presença nos eventos e mantenha seu perfil atualizado.',
                'features' => [
                    'Manter seus dados e informações de artista atualizados.',
                    'Acompanhar vínculos com eventos, line-ups e produções.',
                    'Interagir com o ecossistema de eventos disponível para seu perfil.',
                ],
            ];
        }

        if ($user->is_promoter || $this->roleMatches($role, ['promoter', 'seller', 'vendedor'])) {
            return [
                'subject' => "Seu acesso de promoter no {$name} está pronto",
                'title' => "Você já pode atuar como promoter no {$name}",
                'intro' => 'Sua conta está pronta para acompanhar os eventos e recursos comerciais liberados para seu perfil.',
                'features' => [
                    'Acessar os eventos atribuídos ao seu perfil.',
                    'Acompanhar seus vínculos, vendas e comissões quando disponíveis.',
                    'Manter sua atuação conectada às produções responsáveis.',
                ],
            ];
        }

        return [
            'subject' => "Seu acesso de participante ao {$name} está pronto",
            'title' => "Bem-vindo ao {$name}",
            'intro' => 'Sua conta de participante está pronta para você descobrir eventos e acompanhar sua experiência na plataforma.',
            'features' => [
                'Descobrir eventos e produções.',
                'Comprar, receber e consultar seus ingressos.',
                'Acompanhar suas participações e interagir com a comunidade.',
            ],
        ];
    }

    private function catalogContext(Application $application, string $role, bool $isOwner, ?string $establishment): array
    {
        $name = $application->name;
        return [
            'subject' => "Seu acesso ao {$name} está pronto",
            'title' => $establishment ? "{$establishment} já está vinculado à sua conta" : "Sua empresa já pode operar no {$name}",
            'intro' => $isOwner || $this->roleMatches($role, ['owner', 'manager'])
                ? "Você já tem autonomia para organizar a presença digital da sua empresa no {$name}."
                : "Seu acesso ao {$name} foi liberado conforme o papel atribuído à sua conta.",
            'features' => [
                'Cadastrar e organizar os itens da empresa.',
                'Atualizar informações e disponibilidade do catálogo.',
                'Gerar e compartilhar o catálogo digital e seu QR Code.',
            ],
        ];
    }

    private function restaurantContext(Application $application, string $role, bool $isOwner, ?string $establishment): array
    {
        $name = $application->name;
        $manager = $isOwner || $this->roleMatches($role, ['owner', 'manager', 'gerente', 'admin']);
        return [
            'subject' => "Seu acesso ao {$name} está pronto",
            'title' => $establishment ? "{$establishment} já pode ser gerenciado por você" : "Seu acesso operacional ao {$name} está pronto",
            'intro' => $manager
                ? "Você já pode administrar a operação do restaurante pelo {$name}."
                : 'Você já pode atuar na operação do restaurante conforme as permissões atribuídas ao seu perfil.',
            'features' => $manager ? [
                'Gerenciar restaurante, cardápio e itens.',
                'Controlar vendas de balcão e pedidos.',
                'Acompanhar filas e etapas de preparação dos pedidos.',
                'Administrar a rotina operacional e os recursos liberados para o estabelecimento.',
            ] : [
                'Acompanhar e atualizar pedidos conforme suas permissões.',
                'Atuar nas filas e etapas operacionais liberadas para seu perfil.',
                'Acessar os recursos do restaurante atribuídos à sua função.',
            ],
        ];
    }

    private function schedulingContext(User $user, Application $application, string $role, bool $isOwner, ?string $establishment): array
    {
        $name = $application->name;
        $collaborator = ! $isOwner && ($user->is_barber || $this->roleMatches($role, ['barber', 'barbeiro', 'collaborator', 'colaborador', 'professional', 'profissional']));
        if ($collaborator) {
            return [
                'subject' => "Você foi vinculado a uma equipe no {$name}",
                'title' => $establishment ? "Você agora faz parte da equipe de {$establishment}" : "Seu perfil profissional no {$name} está pronto",
                'intro' => 'Seu acesso é de colaborador: você não é o proprietário do estabelecimento e verá apenas os recursos compatíveis com sua função.',
                'features' => [
                    'Escolher e manter os serviços do estabelecimento que você atende.',
                    'Acompanhar sua agenda e os atendimentos atribuídos a você.',
                    'Gerenciar seu perfil profissional e sua rotina de atendimento.',
                ],
            ];
        }

        return [
            'subject' => "Seu estabelecimento no {$name} está pronto para ser gerenciado",
            'title' => $establishment ? "{$establishment} já está vinculado à sua conta" : "Seu acesso de gestão no {$name} está pronto",
            'intro' => "Você já pode administrar a operação do estabelecimento no {$name}.",
            'features' => [
                'Gerenciar dados, serviços e profissionais do estabelecimento.',
                'Organizar agenda, horários e atendimentos.',
                'Acompanhar a operação e os recursos disponíveis para sua equipe.',
            ],
        ];
    }

    private function crmContext(Application $application, ?string $establishment): array
    {
        $name = $application->name;
        return [
            'subject' => "Seu acesso ao {$name} está pronto",
            'title' => $establishment ? "{$establishment} já está conectado ao {$name}" : "Seu workspace no {$name} está pronto",
            'intro' => 'Você já pode organizar o relacionamento comercial e acompanhar o avanço das oportunidades.',
            'features' => [
                'Gerenciar clientes e oportunidades.',
                'Criar propostas e acompanhar negociações.',
                'Organizar cobranças, pagamentos e follow-ups.',
            ],
        ];
    }

    private function realEstateContext(Application $application, ?string $establishment): array
    {
        $name = $application->name;
        return [
            'subject' => "Seu acesso ao {$name} está pronto",
            'title' => $establishment ? "{$establishment} já está vinculado à sua conta" : "Seu acesso ao {$name} está pronto",
            'intro' => 'Sua conta já pode utilizar os recursos imobiliários liberados para seu perfil.',
            'features' => [
                'Cadastrar e organizar imóveis.',
                'Manter anúncios e informações comerciais atualizados.',
                'Acompanhar contatos, interessados e a operação disponível para seu perfil.',
            ],
        ];
    }

    private function marketContext(Application $application): array
    {
        $name = $application->name;
        return [
            'subject' => "Seu acesso ao {$name} está pronto",
            'title' => "Sua conta no {$name} está pronta",
            'intro' => 'Você já pode utilizar os recursos de acompanhamento e inteligência de mercado disponíveis na plataforma.',
            'features' => [
                'Acompanhar ativos e dados de mercado disponíveis.',
                'Consultar análises e indicadores apresentados pela plataforma.',
                'Organizar sua experiência de acompanhamento do mercado.',
            ],
        ];
    }

    private function genericContext(Application $application, string $role, bool $isOwner, ?string $establishment): array
    {
        return [
            'subject' => "Seu acesso ao {$application->name} está pronto",
            'title' => $establishment ? "{$establishment} já está vinculado à sua conta" : "Seu acesso ao {$application->name} está pronto",
            'intro' => $isOwner
                ? 'Você já tem autonomia para gerenciar os recursos vinculados ao seu estabelecimento nesta plataforma.'
                : 'Seu acesso foi configurado de acordo com o papel e as permissões atribuídas à sua conta.',
            'features' => [
                'Acessar os recursos liberados para o seu perfil.',
                'Gerenciar as informações que estiverem sob sua responsabilidade.',
                'Utilizar as funcionalidades disponíveis conforme suas permissões.',
            ],
        ];
    }

    private function roleFromFlags(User $user): ?string
    {
        return match (true) {
            (bool) $user->is_producer => 'producer',
            (bool) $user->is_promoter => 'promoter',
            (bool) $user->is_barber => 'barber',
            (bool) $user->is_participant => 'participant',
            default => null,
        };
    }

    private function relationshipLabel(string $role, bool $isOwner, bool $isCollaborator): string
    {
        if ($isOwner) return 'Proprietário / responsável';
        if ($isCollaborator) return 'Colaborador';
        if ($this->roleMatches($role, ['producer', 'produtor'])) return 'Produtor';
        if ($this->roleMatches($role, ['artist', 'artista'])) return 'Artista';
        if ($this->roleMatches($role, ['promoter'])) return 'Promoter';
        if ($this->roleMatches($role, ['participant', 'participante'])) return 'Participante';
        if ($this->roleMatches($role, ['manager', 'gerente'])) return 'Gerente';
        return Str::headline($role ?: 'member');
    }

    private function roleMatches(string $role, array $needles): bool
    {
        return collect($needles)->contains(fn ($needle) => Str::contains($role, Str::lower($needle)));
    }
}
