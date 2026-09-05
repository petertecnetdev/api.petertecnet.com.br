<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Domain\Messaging\Services\AppMessagingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppMessagingController extends Controller
{
    public function __construct(private readonly AppMessagingService $messaging)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->messaging->conversations(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            (int) $request->query('per_page', 25),
        ));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->messaging->unreadCount(
                (int) $request->user('api')->id,
                $this->applicationId($request),
            ),
        ]);
    }

    public function people(Request $request): JsonResponse
    {
        return response()->json([
            'people' => $this->messaging->people(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                (string) $request->query('q', ''),
                (int) $request->query('per_page', 20),
            ),
        ]);
    }

    public function createDirect(Request $request): JsonResponse
    {
        $userId = (int) $request->user('api')->id;
        $payload = $request->validate([
            'recipient_user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$userId])],
        ]);

        return response()->json([
            'conversation' => $this->messaging->createDirect(
                $userId,
                (int) $payload['recipient_user_id'],
                $this->applicationId($request),
            ),
        ], 201);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        return response()->json($this->messaging->messages(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            $conversationId,
            (int) $request->query('per_page', 40),
        ));
    }

    public function send(Request $request, int $conversationId): JsonResponse
    {
        $payload = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'type' => ['sometimes', 'string', Rule::in(['text'])],
        ]);

        return response()->json([
            'message' => $this->messaging->send(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                (string) $payload['body'],
            ),
        ], 201);
    }

    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        return response()->json($this->messaging->markRead(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            $conversationId,
        ));
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }
}
