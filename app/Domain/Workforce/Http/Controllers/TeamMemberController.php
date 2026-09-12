<?php

namespace App\Domain\Workforce\Http\Controllers;

use App\Domain\Workforce\Services\TeamMemberService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

class TeamMemberController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TeamMemberService $teamMembers,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);

        $members = $this->teamMembers->list(
            $this->context->id(),
            $request->user(),
            (int) $data['establishment_id'],
        );

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

        $candidates = $this->teamMembers->searchCandidates(
            $this->context->id(),
            $request->user(),
            (int) $data['establishment_id'],
            (string) $data['q'],
            (int) ($data['limit'] ?? 30),
        );

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
            'app_id' => 'nullable|integer|exists:applications,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ]);

        $result = $this->teamMembers->add(
            $this->context->id(),
            $request->user(),
            (int) $data['user_id'],
            (int) $data['establishment_id'],
            $data['role'],
            $data['permissions'] ?? [],
            isset($data['app_id']) ? (int) $data['app_id'] : null,
        );

        if (! $result['created']) {
            return response()->json([
                'error' => 'Usuário já vinculado ao estabelecimento.',
                'employer' => $result['employer'],
            ], 409);
        }

        return response()->json([
            'message' => $result['is_owner']
                ? 'Proprietário adicionado à equipe de atendimento com sucesso.'
                : 'Colaborador adicionado com sucesso.',
            'employer' => $result['employer'],
            'is_owner' => $result['is_owner'],
        ], 201);
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-ZÀ-ÿ\\s]+$/'],
            'email' => 'required|email|max:255',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ]);

        $result = $this->teamMembers->invite(
            $this->context->id(),
            $request->user(),
            (int) $data['establishment_id'],
            (string) $data['first_name'],
            (string) $data['email'],
            (string) $data['role'],
            $data['permissions'] ?? [],
        );

        if (! $result['created']) {
            return response()->json([
                'error' => 'Usuário já vinculado ao estabelecimento.',
                'employer' => $result['employer'],
                'user' => $result['user'],
            ], 409);
        }

        return response()->json([
            'message' => $result['invited']
                ? 'Profissional vinculado e convite enviado com sucesso.'
                : 'Profissional existente vinculado com sucesso.',
            'employer' => $result['employer'],
            'user' => $result['user'],
            'invited' => $result['invited'],
        ], 201);
    }

    public function destroy(Request $request, int $teamMember)
    {
        $this->teamMembers->remove(
            $this->context->id(),
            $request->user(),
            $teamMember,
        );

        return response()->json([
            'success' => true,
            'message' => 'Colaborador removido da equipe com sucesso.',
        ]);
    }
}
