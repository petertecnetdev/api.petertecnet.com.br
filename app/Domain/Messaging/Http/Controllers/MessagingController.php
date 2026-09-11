<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Domain\Messaging\Services\MessageAttachmentDeliveryService;
use App\Domain\Messaging\Services\MessageEngagementService;
use App\Domain\Messaging\Services\MessagingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MessagingController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly MessageAttachmentDeliveryService $attachments,
        private readonly MessageEngagementService $engagement,
    ) {}

    public function index(Request $request)
    {
        $data = $this->messaging->conversations(
            (int) $request->user()->id,
            trim((string) $request->query('q', '')),
            [
                'unread' => $request->boolean('unread'),
                'pinned' => $request->boolean('pinned'),
                'archived' => $request->boolean('archived'),
                'requests' => $request->boolean('requests'),
            ],
        );
        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function people(Request $request)
    {
        return response()->json(['data' => $this->messaging->people((int) $request->user()->id, trim((string) $request->query('q', '')))]);
    }

    public function openDirect(Request $request)
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$userId])]]);
        return response()->json(['data' => $this->messaging->openDirect($userId, (int) $data['user_id'])]);
    }

    public function acceptRequest(Request $request, int $conversationId)
    {
        return response()->json(['data' => $this->messaging->acceptRequest($conversationId, (int) $request->user()->id)]);
    }

    public function rejectRequest(Request $request, int $conversationId)
    {
        $this->messaging->rejectRequest($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Solicitação recusada.']);
    }

    public function createGroup(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:99'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);
        return response()->json(['data' => $this->messaging->createGroup((int) $request->user()->id, $data['title'], $data['participant_ids'])], 201);
    }

    public function showConversation(Request $request, int $conversationId)
    {
        return response()->json(['data' => $this->messaging->conversation($conversationId, (int) $request->user()->id)]);
    }

    public function updateConversation(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'pinned' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'boolean'],
            'notification_level' => ['sometimes', Rule::in(['all', 'mentions', 'none'])],
        ]);
        return response()->json(['data' => $this->messaging->updateConversation($conversationId, (int) $request->user()->id, $data)]);
    }

    public function addParticipants(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:99'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        return response()->json(['data' => $this->messaging->addParticipants($conversationId, (int) $request->user()->id, $data['user_ids'])]);
    }

    public function removeParticipant(Request $request, int $conversationId, int $userId)
    {
        $this->messaging->removeParticipant($conversationId, (int) $request->user()->id, $userId);
        return response()->json(['message' => 'Participante removido.']);
    }

    public function messages(Request $request, int $conversationId)
    {
        return response()->json($this->messaging->messages(
            $conversationId,
            (int) $request->user()->id,
            max(0, (int) $request->query('before', 0)),
            trim((string) $request->query('q', '')),
        ));
    }

    public function scheduledMessages(Request $request, int $conversationId)
    {
        return response()->json(['data' => $this->messaging->scheduledMessages($conversationId, (int) $request->user()->id)]);
    }

    public function send(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['text', 'image', 'video', 'audio', 'file', 'share', 'location', 'system'])],
            'client_uuid' => ['nullable', 'uuid'],
            'scheduled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'metadata' => ['nullable'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:102400', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg,audio/webm,application/pdf,text/plain,application/zip,application/x-zip-compressed'],
        ]);

        $metadata = $data['metadata'] ?? [];
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            abort_if(json_last_error() !== JSON_ERROR_NONE, 422, 'Metadados da mensagem inválidos.');
            $metadata = $decoded;
        }
        $data['metadata'] = is_array($metadata) ? $metadata : [];

        $files = $request->file('attachments', []);
        return response()->json(['data' => $this->messaging->send($conversationId, (int) $request->user()->id, $data, is_array($files) ? $files : [$files])], 201);
    }

    public function editMessage(Request $request, int $conversationId, int $messageId)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        return response()->json(['data' => $this->messaging->editMessage($conversationId, $messageId, (int) $request->user()->id, $data['body'])]);
    }

    public function deleteMessage(Request $request, int $conversationId, int $messageId)
    {
        $this->messaging->deleteMessage($conversationId, $messageId, (int) $request->user()->id);
        return response()->json(['message' => 'Envio cancelado.']);
    }

    public function cancelScheduledMessage(Request $request, int $conversationId, int $messageId)
    {
        $this->messaging->cancelScheduledMessage($conversationId, $messageId, (int) $request->user()->id);
        return response()->json(['message' => 'Agendamento cancelado.']);
    }

    public function react(Request $request, int $conversationId, int $messageId)
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:32']]);
        return response()->json(['data' => $this->messaging->react($conversationId, $messageId, (int) $request->user()->id, $data['emoji'])]);
    }

    public function removeReaction(Request $request, int $conversationId, int $messageId)
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:32']]);
        return response()->json(['data' => $this->messaging->removeReaction($conversationId, $messageId, (int) $request->user()->id, $data['emoji'])]);
    }

    public function pinMessage(Request $request, int $conversationId, int $messageId)
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        return response()->json(['data' => $this->messaging->pinMessage($conversationId, $messageId, (int) $request->user()->id, (bool) $data['pinned'])]);
    }

    public function markRead(Request $request, int $conversationId)
    {
        $this->messaging->markRead($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Conversa marcada como lida.']);
    }

    public function archive(Request $request, int $conversationId)
    {
        $this->messaging->archive($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Conversa arquivada.']);
    }

    public function typing(Request $request, int $conversationId)
    {
        $data = $request->validate(['typing' => ['required', 'boolean']]);
        $this->messaging->typing($conversationId, (int) $request->user()->id, (bool) $data['typing']);
        return response()->json(['ok' => true]);
    }

    public function heartbeat(Request $request)
    {
        return response()->json(['data' => $this->messaging->heartbeat((int) $request->user()->id)]);
    }

    public function presence(Request $request, int $userId)
    {
        return response()->json(['data' => $this->messaging->presence((int) $request->user()->id, $userId)]);
    }

    public function conversationActivity(Request $request, int $conversationId)
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->engagement->markConversationActive($conversationId, (int) $request->user()->id, (bool) $data['active']);
        return response()->json(['ok' => true]);
    }

    public function pushPublicKey(Request $request)
    {
        return response()->json(['public_key' => $this->engagement->pushPublicKey()]);
    }

    public function subscribePush(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:4096'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:1024'],
            'contentEncoding' => ['nullable', 'string', 'max:24'],
        ]);

        return response()->json(['data' => $this->engagement->subscribePush(
            (int) $request->user()->id,
            $data,
            $request->userAgent(),
        )], 201);
    }

    public function unsubscribePush(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'url', 'max:4096']]);
        $this->engagement->unsubscribePush((int) $request->user()->id, $data['endpoint']);
        return response()->json(['message' => 'Assinatura push removida.']);
    }

    public function engagementClick(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $conversationId = $this->engagement->trackEmailClick((int) $request->user()->id, $data['token']);
        return response()->json(['conversation_id' => $conversationId]);
    }

    public function metrics(Request $request)
    {
        abort_unless($request->user()->hasProfile('Administrador'), 403, 'Sem permissão para consultar métricas do Direct.');
        return response()->json(['data' => $this->engagement->metrics(max(1, min((int) $request->query('days', 30), 365)))]);
    }

    public function settings(Request $request)
    {
        return response()->json(['data' => $this->messaging->settings((int) $request->user()->id)]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'allow_messages_from' => ['required', Rule::in(['everyone', 'requests', 'none'])],
            'show_activity_status' => ['required', 'boolean'],
            'send_read_receipts' => ['required', 'boolean'],
            'allow_group_invites' => ['required', 'boolean'],
            'muted_words' => ['nullable', 'array', 'max:100'],
            'muted_words.*' => ['string', 'max:80'],
            'email_new_messages' => ['required', 'boolean'],
            'push_new_messages' => ['required', 'boolean'],
            'unread_reminders' => ['required', 'boolean'],
            'digest_messages' => ['required', 'boolean'],
            'include_message_preview' => ['required', 'boolean'],
            'email_cooldown_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'first_reminder_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'second_reminder_minutes' => ['required', 'integer', 'min:60', 'max:4320'],
        ]);
        return response()->json(['data' => $this->messaging->updateSettings((int) $request->user()->id, $data)]);
    }

    public function block(Request $request, int $userId)
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(['block', 'restrict'])]]);
        $this->messaging->block((int) $request->user()->id, $userId, $data['kind'] ?? 'block');
        return response()->json(['message' => 'Preferência aplicada.']);
    }

    public function unblock(Request $request, int $userId)
    {
        $this->messaging->unblock((int) $request->user()->id, $userId);
        return response()->json(['message' => 'Bloqueio removido.']);
    }

    public function report(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'message_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:80'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['data' => $this->messaging->report($conversationId, (int) $request->user()->id, $data['message_id'] ?? null, $data['reason'], $data['details'] ?? null)], 201);
    }

    public function attachment(Request $request, int $attachmentId)
    {
        return $this->attachments->response($attachmentId, (int) $request->user()->id);
    }

    public function startCall(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['audio', 'video'])],
            'metadata' => ['nullable', 'array'],
        ]);
        return response()->json(['data' => $this->messaging->startCall(
            $conversationId,
            (int) $request->user()->id,
            $data['type'],
            $data['metadata'] ?? [],
        )], 201);
    }

    public function updateCall(Request $request, int $conversationId, int $callId)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ringing', 'active', 'ended', 'declined', 'missed'])],
            'metadata' => ['nullable', 'array'],
        ]);
        return response()->json(['data' => $this->messaging->updateCall($conversationId, $callId, (int) $request->user()->id, $data['status'], $data['metadata'] ?? [])]);
    }
}
