<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Finance\Exceptions\SubscriptionUpgradeRequired;
use App\Domain\Finance\Services\EntitlementAccessService;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Mail\InviteUserMail;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamMemberService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly EntitlementAccessService $entitlements,
    ) {}

    public function list(int $applicationId, User $actor, int $establishmentId): Collection
    {
        $establishment = $this->manageableEstablishment($applicationId, $actor, $establishmentId);

        return Employer::query()
            ->with(['user', 'establishment.user'])
            ->where('establishment_id', $establishment->id)
            ->orderBy('id')
            ->get();
    }

    public function searchCandidates(
        int $applicationId,
        User $actor,
        int $establishmentId,
        string $term,
        int $limit = 30,
    ): Collection {
        $establishment = $this->manageableEstablishment($applicationId, $actor, $establishmentId);
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term) ?: '';

        $users = User::query()
            ->select([
                'id',
                'first_name',
                'last_name',
                'user_name',
                'email',
                'phone',
                'city',
                'uf',
                'avatar',
            ])
            ->where(function ($query) use ($term, $digits) {
                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }

                $query->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('user_name', 'like', '%'.ltrim($term, '@').'%');

                if ($digits !== '') {
                    $query->orWhere('phone', 'like', '%'.$digits.'%')
                        ->orWhere('cpf', $digits);
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get();

        $membersByUser = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy(fn (Employer $member) => (int) $member->user_id);

        return $users->map(function (User $user) use ($membersByUser) {
            $member = $membersByUser->get((int) $user->id);

            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'uf' => $user->uf,
                'avatar' => $user->avatar,
                'is_team_member' => $member !== null,
                'team_member' => $member ? [
                    'id' => $member->id,
                    'role' => $member->role,
                    'permissions' => $member->permissions ?? [],
                ] : null,
            ];
        })->values();
    }

    public function add(
        int $applicationId,
        User $actor,
        int $userId,
        int $establishmentId,
        string $role,
        array $permissions = [],
        ?int $legacyApplicationId = null,
        bool $notifyMember = true,
    ): array {
        if ($legacyApplicationId !== null && $legacyApplicationId !== $applicationId) {
            throw ValidationException::withMessages([
                'app_id' => ['A aplicação informada não corresponde ao contexto desta operação.'],
            ]);
        }

        $establishment = $this->manageableEstablishment($applicationId, $actor, $establishmentId);

        $existing = Employer::query()
            ->where('user_id', $userId)
            ->where('establishment_id', $establishment->id)
            ->first();

        if ($existing) {
            return [
                'created' => false,
                'employer' => $existing->load(['user', 'establishment.user']),
            ];
        }

        $isOwner = $userId === (int) $establishment->user_id;
        if (! $isOwner) {
            $decision = $this->entitlements->check($applicationId, (int) $actor->id, 'staff.management');

            if (! $decision['allowed']) {
                throw new SubscriptionUpgradeRequired(
                    'staff.management',
                    $decision['plan_code'],
                    'Seu plano atual não inclui colaboradores adicionais. Faça upgrade para adicionar sua equipe.',
                );
            }
        }

        $employer = Employer::create([
            'user_id' => $userId,
            'establishment_id' => $establishment->id,
            'role' => $role,
            'permissions' => $permissions,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $employer->load(['user', 'establishment.user']);

        if (! $isOwner && $establishment->user?->email) {
            $this->notifications->queueMailable(
                $establishment->user->email,
                new OwnerNotifiedNewCollaborator($establishment, $employer),
            );
        }

        if ($notifyMember && $employer->user?->email) {
            $this->notifications->queueMailable(
                $employer->user->email,
                new NewEmployerCollaborator($establishment, $employer),
            );
        }

        return [
            'created' => true,
            'employer' => $employer,
            'is_owner' => $isOwner,
        ];
    }

    public function invite(
        int $applicationId,
        User $actor,
        int $establishmentId,
        string $firstName,
        string $email,
        string $role,
        array $permissions = [],
    ): array {
        $email = strtolower(trim($email));
        $firstName = trim($firstName);

        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser) {
            $result = $this->add(
                $applicationId,
                $actor,
                (int) $existingUser->id,
                $establishmentId,
                $role,
                $permissions,
            );

            return [
                ...$result,
                'invited' => false,
                'user' => $existingUser,
            ];
        }

        // Validate ownership and paid staff entitlement before creating an account,
        // so a rejected invitation never leaves an orphan pending user behind.
        $this->manageableEstablishment($applicationId, $actor, $establishmentId);
        $decision = $this->entitlements->check($applicationId, (int) $actor->id, 'staff.management');
        if (! $decision['allowed']) {
            throw new SubscriptionUpgradeRequired(
                'staff.management',
                $decision['plan_code'],
                'Seu plano atual não inclui colaboradores adicionais. Faça upgrade para adicionar sua equipe.',
            );
        }

        $application = Application::query()->findOrFail($applicationId);
        $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $result = DB::transaction(function () use (
            $applicationId,
            $actor,
            $establishmentId,
            $firstName,
            $email,
            $role,
            $permissions,
            $rawCode,
        ) {
            $user = User::create([
                'first_name' => $firstName,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'user_name' => $this->uniqueUsername($firstName),
                'verification_code' => Hash::make($rawCode),
                'verification_code_expires_at' => now()->addDay(),
            ]);

            $user->applications()->attach($applicationId, [
                'status' => 'pending',
                'role' => 'client',
                'metadata' => json_encode([
                    'invited_by' => $actor->id,
                    'invited_at' => now()->toIso8601String(),
                    'source' => 'workforce',
                    'establishment_id' => $establishmentId,
                ], JSON_UNESCAPED_UNICODE),
                'joined_at' => null,
            ]);

            $teamMember = $this->add(
                $applicationId,
                $actor,
                (int) $user->id,
                $establishmentId,
                $role,
                $permissions,
                null,
                false,
            );

            return [
                ...$teamMember,
                'invited' => true,
                'user' => $user,
            ];
        });

        $this->notifications->queueMailable(
            $email,
            new InviteUserMail(
                $result['user'],
                $rawCode,
                $application->name,
                $application->url,
            ),
        );

        return $result;
    }

    public function remove(int $applicationId, User $actor, int $teamMemberId): void
    {
        $employer = Employer::query()
            ->whereKey($teamMemberId)
            ->firstOrFail();

        $this->manageableEstablishment($applicationId, $actor, (int) $employer->establishment_id);
        $employer->delete();
    }

    private function manageableEstablishment(int $applicationId, User $actor, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->with('user')
            ->whereKey($establishmentId)
            ->forApplication($applicationId)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $isOwner = (int) $actor->id === (int) $establishment->user_id;
        $isAdmin = method_exists($actor, 'hasProfile') && $actor->hasProfile('Administrador');

        if (! $isOwner && ! $isAdmin) {
            throw new AuthorizationException('Somente o proprietário pode gerenciar os colaboradores deste estabelecimento.');
        }

        return $establishment;
    }

    private function uniqueUsername(string $firstName): string
    {
        $base = Str::slug($firstName, '.');
        $base = trim($base, '.') ?: 'usuario';
        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('user_name', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'.'.$suffix;
        }

        return $candidate;
    }
}
