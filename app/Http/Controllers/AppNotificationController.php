<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

class AppNotificationController extends ApiController
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $this->context->requireCapability('notifications');
        $userId = (int) $request->user()->id;
        $limit = max(1, min(100, (int) $request->query('limit', $request->query('per_page', 20))));
        $query = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId);

        if ($request->boolean('unread')) $query->whereNull('read_at');

        $notifications = (clone $query)->latest('id')->limit($limit)->get();
        $unreadCount = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function unreadCount(Request $request)
    {
        $this->context->requireCapability('notifications');
        $count = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['success' => true, 'unread_count' => $count]);
    }

    public function markRead(Request $request, int $id)
    {
        $this->context->requireCapability('notifications');
        $notification = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['success' => true, 'notification' => $notification->fresh()]);
    }

    public function markAllRead(Request $request)
    {
        $this->context->requireCapability('notifications');
        $updated = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true, 'updated' => $updated]);
    }
}
