<?php

namespace App\Http\Controllers;

use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class RasoioEmployerController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'app_id' => 'required|integer',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ]);

        $rasoioAppId = $this->rasoioAppId();

        abort_unless(
            (int) $data['app_id'] === $rasoioAppId,
            422,
            'A aplicação informada não corresponde à Rasoio.'
        );

        $establishment = Establishment::with('user')
            ->where('app_id', $rasoioAppId)
            ->findOrFail($data['establishment_id']);

        $actor = Auth::user();
        $isOwner = $actor && (int) $actor->id === (int) $establishment->user_id;
        $isAdmin = $actor && $actor->hasProfile('Administrador');

        abort_unless($isOwner || $isAdmin, 403, 'Somente o proprietário pode gerenciar os colaboradores deste estabelecimento.');

        $existing = Employer::query()
            ->where('user_id', $data['user_id'])
            ->where('establishment_id', $establishment->id)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'Usuário já vinculado ao estabelecimento.',
                'employer' => $existing->load(['user', 'establishment.user']),
            ], 409);
        }

        // Na Rasoio, propriedade e atendimento são papéis independentes.
        // O proprietário pode também possuir um vínculo Employer e, assim,
        // ter agenda, receber agendamentos e atender clientes normalmente.
        $employer = Employer::create([
            'user_id' => $data['user_id'],
            'establishment_id' => $establishment->id,
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? [],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $employer->load(['user', 'establishment.user']);

        $isOwnerBecomingEmployer = (int) $employer->user_id === (int) $establishment->user_id;

        // Evita dois e-mails para a mesma pessoa quando o próprio proprietário
        // é adicionado à equipe de atendimento.
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

    private function rasoioAppId(): int
    {
        $appId = Application::query()
            ->where('slug', 'rasoio')
            ->value('id');

        abort_if(! $appId, 503, 'Aplicação Rasoio não está registrada na API.');

        return (int) $appId;
    }
}
