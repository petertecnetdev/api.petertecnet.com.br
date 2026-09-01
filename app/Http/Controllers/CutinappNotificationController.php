<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappNotificationController extends Controller
{
    private const APP = 'cutinapp';

    public function index(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $data = $request->validate([
            'unread' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id);

        if ($request->boolean('unread')) $query->whereNull('read_at');

        return response()->json([
            'notifications' => $query->latest()->paginate((int) ($data['per_page'] ?? 25)),
            'unread_count' => AppNotification::query()
                ->where('app_id', $appId)
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, int $notificationId)
    {
        $user = $this->requestUser($request);
        $notification = AppNotification::query()
            ->where('app_id', $this->applicationId())
            ->where('user_id', $user->id)
            ->findOrFail($notificationId);

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['message' => 'Notificação marcada como lida.', 'notification' => $notification]);
    }

    public function markAllRead(Request $request)
    {
        $user = $this->requestUser($request);
        $updated = AppNotification::query()
            ->where('app_id', $this->applicationId())
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notificações marcadas como lidas.', 'updated' => $updated]);
    }

    private function applicationId(): int
    {
        $id = Application::query()->where('slug', self::APP)->where('is_active', true)->value('id');
        abort_unless($id, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $id;
    }

    private function requestUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());
        abort_if($token === '', 401, 'Sessão inválida ou expirada. Faça login novamente.');
        try { $user = JWTAuth::setToken($token)->authenticate(); } catch (\Throwable) { $user = null; }
        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }
}
