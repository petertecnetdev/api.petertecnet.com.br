<?php

namespace App\Http\Controllers;

use App\Models\ImpersonationSession;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationSsoExchangeController extends Controller
{
    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly EcosystemSsoController $sso,
    ) {
    }

    public function exchange(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('handoff_code'));

        if ($code !== '' && ImpersonationSession::query()
            ->where('handoff_token_hash', hash('sha256', $code))
            ->exists()) {
            $data = $this->impersonation->exchange($code, $request->input('application'));

            return response()->json([
                'message' => 'Acesso administrativo temporário concluído.',
                'data' => $data,
            ]);
        }

        return $this->sso->exchange($request);
    }
}
