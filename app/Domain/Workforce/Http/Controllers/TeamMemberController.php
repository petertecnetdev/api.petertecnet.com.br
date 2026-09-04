<?php

namespace App\Domain\Workforce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);

        $establishment = $this->manageableEstablishment(
            $request,
            (int) $data['establishment_id']
        );

        $members = Employer::query()
            ->with(['user', 'establishment.user'])
            ->where('establishment_id', $establishment->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $members,
            'count' => $members->count(),
        ]);
    }

    public function candidates(Request $request)
    {
        $data = $request->validate([
            'establishment_id' => 'required|integer|exists:establishments,id',
            'q' => 'required|string|min:2|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $establishment = $this->manageableEstablishment(
            $request,
            (int) $data['establishment_id']
        );

        $term = trim((string) $data['q']);
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
            ->limit((int) ($data['limit'] ?? 30))
            ->get();

        $membersByUser = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy(fn (Employer $member) => (int) $member->user_id);

        $candidates = $users->map(function (User $user) use ($membersByUser) {
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

        return response()->json([
            'success' => true,
            'data' => $candidates,
            'count' => $candidates->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            // Legacy clients may still send app_id. Context is authoritative.
            'app_id' => 'nullable|integer|exists:applications,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ]);

        if (isset($data['app_id']) && (int) $data['app_id'] !== $this->context->id()) {
            throw ValidationException::withMessages([
                'app_id' => ['A aplicação informada não corresponde ao contexto desta operação.'],
            ]);
        }

        $establishment = $this->manageableEstablishment(
            $request,
            (int) $data['establishment_id']
        );
        $actor = $request->user();

        $existing = Employer::query()
            ->where('user_id', (int) $data['user_id'])
            ->where('establishment_id', $establishment->id)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'Usuário já vinculado ao estabelecimento.',
                'employer' => $existing->load(['user', 'establishment.user']),
            ], 409);
        }

        $employer = Employer::create([
            'user_id' => (int) $data['user_id'],
            'establishment_id' => $establishment->id,
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? [],
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $employer->load(['user', 'establishment.user']);

        $isOwnerBecomingEmployer = (int) $employer->user_id === (int) $establishment->user_id;
        if (! $isOwnerBecomingEmployer && $establishment->user?->email) {
            Mail::to($establishment->user->email)
                ->queue(new OwnerNotifiedNewCollaborator($establishment, $employer));
        }
        if ($employer->user?->email) {
            Mail::to($employer->user->email)
                ->queue(new NewEmployerCollaborator($establishment, $employer));
        }

        return response()->json([
            'message' => $isOwnerBecomingEmployer
                ? 'Proprietário adicionado à equipe de atendimento com sucesso.'
                : 'Colaborador adicionado com sucesso.',
            'employer' => $employer,
            'is_owner' => $isOwnerBecomingEmployer,
        ], 201);
    }

    public function destroy(Request $request, int $teamMember)
    {
        $employer = Employer::query()
            ->with(['user', 'establishment.user'])
            ->whereKey($teamMember)
            ->firstOrFail();

        $this->manageableEstablishment($request, (int) $employer->establishment_id);
        $employer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Colaborador removido da equipe com sucesso.',
        ]);
    }

    private function manageableEstablishment(Request $request, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->with('user')
            ->whereKey($establishmentId)
            ->forApplication($this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $actor = $request->user();
        $isOwner = $actor && (int) $actor->id === (int) $establishment->user_id;
        $isAdmin = $actor && method_exists($actor, 'hasProfile') && $actor->hasProfile('Administrador');

        abort_unless(
            $isOwner || $isAdmin,
            403,
            'Somente o proprietário pode gerenciar os colaboradores deste estabelecimento.'
        );

        return $establishment;
    }
}
