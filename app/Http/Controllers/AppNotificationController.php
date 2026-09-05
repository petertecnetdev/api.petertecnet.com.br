<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\NotificationPreference;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

class AppNotificationController extends ApiController
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $perPage = max(1, min(50, (int) $request->query('per_page', $request->query('limit', 20))));

        $query = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId);

        $notifications = (clone $query)
            ->latest('id')
            ->paginate($perPage)
            ->appends($request->query());
        $unreadCount = (clone $query)->whereNull('read_at')->count();
        $preference = NotificationPreference::query()->firstOrCreate(
            [
                'app_id' => $this->context->id(),
                'user_id' => $userId,
            ],
            ['email_enabled' => true],
        );

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
            'preferences' => [
                'email_enabled' => (bool) $preference->email_enabled,
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        $count = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['success' => true, 'unread_count' => $count]);
    }

    public function markRead(Request $request, int $id)
    {
        $notification = AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'notification' => $notification->fresh(),
        ]);
    }

    public function markAllRead(Request $request)
    {
        if ($request->boolean('preference_only')) {
            $validated = $request->validate([
                'email_enabled' => ['required', 'boolean'],
            ]);

            $preference = NotificationPreference::query()->updateOrCreate(
                [
                    'app_id' => $this->context->id(),
                    'user_id' => (int) $request->user()->id,
                ],
                ['email_enabled' => (bool) $validated['email_enabled']],
            );

            return response()->json([
                'success' => true,
                'preferences' => [
                    'email_enabled' => (bool) $preference->email_enabled,
                ],
            ]);
        }

        AppNotification::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', (int) $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
