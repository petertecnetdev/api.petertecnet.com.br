<?php

namespace App\Domain\Workforce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\Employer;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

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

        $establishment = Establishment::query()
            ->with('user')
            ->whereKey((int) $data['establishment_id'])
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $actor = $request->user();
        $isOwner = $actor && (int) $actor->id === (int) $establishment->user_id;
        $isAdmin = $actor && method_exists($actor, 'hasProfile') && $actor->hasProfile('Administrador');
        abort_unless($isOwner || $isAdmin, 403, 'Somente o proprietário pode gerenciar os colaboradores deste estabelecimento.');

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
}
