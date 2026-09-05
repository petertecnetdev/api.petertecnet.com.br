<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Domain\Messaging\Services\AppMessagingPrivacyService;
use App\Domain\Messaging\Services\AppMessagingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppMessagingController extends Controller
{
    public function __construct(
        private readonly AppMessagingService $messaging,
        private readonly AppMessagingPrivacyService $privacy,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->messaging->conversations(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            (int) $request->query('per_page', 25),
            $request->boolean('archived'),
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

    public function blockStatus(Request $request, int $userId): JsonResponse
    {
        return response()->json($this->privacy->blockStatus(
            (int) $request->user('api')->id,
            $userId,
            $this->applicationId($request),
        ));
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

    public function searchMessages(Request $request, int $conversationId): JsonResponse
    {
        $payload = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'messages' => $this->messaging->searchMessages(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                (string) $payload['q'],
            ),
        ]);
    }

    public function send(Request $request, int $conversationId): JsonResponse
    {
        $payload = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'reply_to_message_id' => ['nullable', 'integer'],
            'client_token' => ['nullable', 'string', 'max:64'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => [
                'file',
                'max:20480',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,audio/mpeg,audio/mp4,audio/ogg,audio/wav,application/pdf,text/plain,application/zip,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ]);

        return response()->json([
            'message' => $this->messaging->send(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                $payload['body'] ?? null,
                $request->file('attachments', []),
                isset($payload['reply_to_message_id']) ? (int) $payload['reply_to_message_id'] : null,
                $payload['client_token'] ?? null,
            ),
        ], 201);
    }

    public function updateMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $payload = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        return response()->json([
            'message' => $this->messaging->updateMessage(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                $messageId,
                (string) $payload['body'],
            ),
        ]);
    }

    public function deleteMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        return response()->json([
            'message' => $this->messaging->deleteMessage(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                $messageId,
            ),
        ]);
    }

    public function react(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $payload = $request->validate([
            'emoji' => ['required', 'string', Rule::in(AppMessagingService::REACTIONS)],
        ]);

        return response()->json([
            'message' => $this->messaging->toggleReaction(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                $messageId,
                (string) $payload['emoji'],
            ),
        ]);
    }

    public function typing(Request $request, int $conversationId): JsonResponse
    {
        $payload = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ]);

        $this->messaging->typing(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            $conversationId,
            (bool) $payload['is_typing'],
        );

        return response()->json(['ok' => true]);
    }

    public function updateConversation(Request $request, int $conversationId): JsonResponse
    {
        $payload = $request->validate([
            'archived' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
            'muted_until' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json([
            'conversation' => $this->messaging->updatePreferences(
                (int) $request->user('api')->id,
                $this->applicationId($request),
                $conversationId,
                $payload,
            ),
        ]);
    }

    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        return response()->json($this->messaging->markRead(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            $conversationId,
        ));
    }

    public function block(Request $request, int $userId): JsonResponse
    {
        $this->messaging->blockUser(
            (int) $request->user('api')->id,
            $userId,
            $this->applicationId($request),
        );

        return response()->json($this->privacy->blockStatus(
            (int) $request->user('api')->id,
            $userId,
            $this->applicationId($request),
        ));
    }

    public function unblock(Request $request, int $userId): JsonResponse
    {
        $this->messaging->unblockUser(
            (int) $request->user('api')->id,
            $userId,
            $this->applicationId($request),
        );

        return response()->json($this->privacy->blockStatus(
            (int) $request->user('api')->id,
            $userId,
            $this->applicationId($request),
        ));
    }

    public function report(Request $request, int $userId): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', Rule::in(AppMessagingService::REPORT_REASONS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'message_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'report' => $this->messaging->reportUser(
                (int) $request->user('api')->id,
                $userId,
                $this->applicationId($request),
                (string) $payload['reason'],
                $payload['description'] ?? null,
                isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null,
                isset($payload['message_id']) ? (int) $payload['message_id'] : null,
            ),
        ], 201);
    }

    public function attachment(
        Request $request,
        int $conversationId,
        int $messageId,
        int $attachmentId,
    ): StreamedResponse {
        return $this->messaging->downloadAttachment(
            (int) $request->user('api')->id,
            $this->applicationId($request),
            $conversationId,
            $messageId,
            $attachmentId,
        );
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }
}
