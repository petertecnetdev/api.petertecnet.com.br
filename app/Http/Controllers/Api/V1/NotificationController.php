<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $limit = max(1, min(50, (int) $request->query('limit', 20)));

        return response()->json([
            'success' => true,
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'notifications' => (clone $query)->latest('id')->limit($limit)->get(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unread_count' => $this->query($request)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $model = $this->query($request)->findOrFail($notification);
        if (! $model->read_at) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'notification' => $model->fresh(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->query($request)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    private function query(Request $request)
    {
        return AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id);
    }
}
