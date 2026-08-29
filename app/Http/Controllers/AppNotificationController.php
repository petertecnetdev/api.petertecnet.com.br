<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\Request;

class AppNotificationController extends ApiController
{
    private const RASOIO_APP_ID = 1;

    public function rasoioIndex(Request $request)
    {
        return $this->indexForApp($request, self::RASOIO_APP_ID);
    }

    public function rasoioUnreadCount(Request $request)
    {
        return $this->unreadCountForApp($request, self::RASOIO_APP_ID);
    }

    public function rasoioMarkRead(Request $request, int $id)
    {
        return $this->markReadForApp($request, self::RASOIO_APP_ID, $id);
    }

    public function rasoioMarkAllRead(Request $request)
    {
        return $this->markAllReadForApp($request, self::RASOIO_APP_ID);
    }

    private function indexForApp(Request $request, int $appId)
    {
        $userId = (int) $request->user()->id;
        $limit = max(1, min(50, (int) $request->query('limit', 20)));

        $query = AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', $userId);

        $notifications = (clone $query)
            ->latest('id')
            ->limit($limit)
            ->get();

        $unreadCount = (clone $query)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    private function unreadCountForApp(Request $request, int $appId)
    {
        $count = AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    private function markReadForApp(Request $request, int $appId, int $id)
    {
        $notification = AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        if (!$notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'notification' => $notification->fresh(),
        ]);
    }

    private function markAllReadForApp(Request $request, int $appId)
    {
        AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
