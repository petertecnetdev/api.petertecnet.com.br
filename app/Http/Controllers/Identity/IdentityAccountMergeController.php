<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Services\IdentityAccountMergeService;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityChallengeService;
use App\Domain\Identity\Services\IdentityIdentifierService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class IdentityAccountMergeController extends Controller
{
    public function __construct(
        private readonly IdentityIdentifierService $identifiers,
        private readonly IdentityAccountMergeService $merges,
        private readonly IdentityChallengeService $challenges,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function candidates(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.duplicate_merge', true), 404);
        return response()->json([
            'success' => true,
            'data' => $this->identifiers->duplicateCandidates($request->user('api')),
        ]);
    }

    public function begin(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.duplicate_merge', true), 404);
        $data = $request->validate(['source_identifier' => ['required', 'string', 'max:255']]);
        $target = $request->user('api');
        $source = $this->identifiers->resolve($data['source_identifier']);
        abort_unless($source && (int) $source->id !== (int) $target->id, 404, 'A outra conta não foi encontrada.');
        abort_unless($source->email && $source->email_verified_at, 409, 'A conta de origem precisa ter um e-mail verificado antes da consolidação.');

        $preview = $this->merges->preview($target, $source);
        $issued = $this->challenges->issue(
            'account_merge_confirm',
            $source,
            null,
            ['target_user_id' => $target->id],
            15,
            $request
        );
        $url = rtrim((string) config('identity.account_url', 'https://petertecnet.com.br'), '/')
            .'/?identity_merge_confirm='.rawurlencode($issued['token']);

        Mail::raw(
            "Foi solicitada a consolidação desta Conta Peter Tecnet com outra conta que você controla.\n\nConfirme somente se reconhece a operação:\n{$url}\n\nO link expira rapidamente e só pode ser usado uma vez.",
            fn ($mail) => $mail->to($source->email)->subject('Confirme a consolidação de contas · Peter Tecnet')
        );
        $this->audit->record('account_merge_requested', $target, $request, null, ['source_user_id' => $source->id, 'automatic' => $preview['can_merge_automatically']], true);

        return response()->json(['success' => true, 'data' => $preview, 'message' => 'Enviamos a confirmação para a outra conta.']);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $challenge = $this->challenges->consume('account_merge_confirm', $data['token']);
        if (! $challenge || ! $challenge->user) {
            return response()->json(['success' => false, 'code' => 'ACCOUNT_MERGE_INVALID', 'message' => 'Confirmação inválida ou expirada.'], 410);
        }

        $target = User::query()->find((int) data_get($challenge->payload, 'target_user_id'));
        if (! $target) {
            return response()->json(['success' => false, 'code' => 'ACCOUNT_MERGE_TARGET_MISSING'], 410);
        }
        $source = $challenge->user;
        $preview = $this->merges->preview($target, $source);
        if (! $preview['can_merge_automatically']) {
            $this->audit->record('account_merge_blocked', $target, $request, null, ['source_user_id' => $source->id, 'blockers' => $preview['blockers']], true);
            return response()->json([
                'success' => false,
                'code' => 'ACCOUNT_MERGE_REVIEW_REQUIRED',
                'message' => 'A consolidação automática foi bloqueada porque a conta possui dados operacionais. Nenhum dado foi alterado.',
                'data' => $preview,
            ], 409);
        }

        $result = $this->merges->merge($target, $source);
        $this->audit->record('account_merge_completed', $target, $request, null, ['source_user_id' => $source->id], true);
        return response()->json(['success' => true, 'data' => $result]);
    }
}
