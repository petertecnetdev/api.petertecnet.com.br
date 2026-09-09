<?php

namespace App\Http\Controllers;

use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UserInvitationController extends Controller
{
    public function __construct(private readonly UserInvitationService $invitations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->input('limit', 10), 50));

        return response()->json([
            'data' => $this->invitations->listRecent($limit),
        ]);
    }

    public function storeProspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'persona' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        return $this->respond($this->invitations->createProspect(
            $data,
            $request->user('api')?->id,
        ));
    }

    public function show(string $token): JsonResponse
    {
        return $this->respond($this->invitations->showByToken($token));
    }

    public function activate(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'verification_code' => ['required', 'string', 'min:6', 'max:12'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        return $this->respond($this->invitations->activate(
            $token,
            $data['verification_code'],
            $data['password'],
        ));
    }

    public function resend(Request $request, string $invitation): JsonResponse
    {
        return $this->respond($this->invitations->resend(
            (int) $invitation,
            $request->user('api')?->id,
        ));
    }

    public function revoke(string $invitation): JsonResponse
    {
        return $this->respond($this->invitations->revoke((int) $invitation));
    }

    private function respond(array $result): JsonResponse
    {
        return response()->json($result['body'] ?? [], (int) ($result['status'] ?? 500));
    }
}
