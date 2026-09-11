<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Domain\Messaging\Services\MessagingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function index(Request $request)
    {
        $filter = (string) $request->query('filter', 'all');
        abort_unless(in_array($filter, ['all', 'unread', 'archived'], true), 422, 'Filtro inválido.');
        $data = $this->messaging->conversations(
            (int) $request->user()->id,
            trim((string) $request->query('q', '')),
            $filter,
        );

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function people(Request $request)
    {
        return response()->json([
            'data' => $this->messaging->people((int) $request->user()->id, trim((string) $request->query('q', ''))),
        ]);
    }

    public function openDirect(Request $request)
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$userId])],
        ]);

        return response()->json(['data' => $this->messaging->openDirect($userId, (int) $data['user_id'])]);
    }

    public function showConversation(Request $request, int $conversationId)
    {
        return response()->json(['data' => $this->messaging->conversation($conversationId, (int) $request->user()->id)]);
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

    public function send(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['text', 'share', 'image', 'video', 'audio', 'file'])],
            'metadata' => ['nullable'],
            'attachments' => ['nullable', 'array', 'max:6'],
            'attachments.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,mp4,webm,mp3,m4a,wav,ogg,pdf,txt,doc,docx,xls,xlsx,ppt,pptx'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:7200000'],
        ]);

        $metadata = $this->normalizedMetadata($data['metadata'] ?? null);
        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $mime = (string) $file->getMimeType();
            $kind = str_starts_with($mime, 'image/') ? 'image'
                : (str_starts_with($mime, 'video/') ? 'video'
                    : (str_starts_with($mime, 'audio/') ? 'audio' : 'file'));
            $path = $file->store('messaging/'.now()->format('Y/m'), 'public');
            $attachments[] = [
                'kind' => $kind,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'duration_ms' => $kind === 'audio' ? ($data['duration_ms'] ?? null) : null,
            ];
        }

        $type = (string) ($data['type'] ?? ($attachments[0]['kind'] ?? 'text'));
        $message = $this->messaging->send(
            $conversationId,
            (int) $request->user()->id,
            $data['body'] ?? null,
            isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
            $type,
            $metadata,
            $attachments,
        );

        return response()->json(['data' => $message], 201);
    }

    public function updateMessage(Request $request, int $messageId)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        return response()->json([
            'data' => $this->messaging->editMessage($messageId, (int) $request->user()->id, $data['body']),
        ]);
    }

    public function deleteMessage(Request $request, int $messageId)
    {
        $this->messaging->deleteMessage($messageId, (int) $request->user()->id);
        return response()->json(['message' => 'Mensagem excluída.']);
    }

    public function reaction(Request $request, int $messageId)
    {
        $data = $request->validate([
            'reaction' => ['required', 'string', 'max:24', Rule::in(['❤️', '😂', '🔥', '👍', '👏', '😮', '😢'])],
        ]);
        return response()->json([
            'data' => $this->messaging->toggleReaction($messageId, (int) $request->user()->id, $data['reaction']),
        ]);
    }

    public function markDelivered(Request $request, int $conversationId)
    {
        $this->messaging->markDelivered($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Mensagens marcadas como entregues.']);
    }

    public function markRead(Request $request, int $conversationId)
    {
        $this->messaging->markRead($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Conversa marcada como lida.']);
    }

    public function state(Request $request, int $conversationId)
    {
        $data = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        abort_if($data === [], 422, 'Nenhum estado informado.');
        return response()->json([
            'data' => $this->messaging->updateConversationState($conversationId, (int) $request->user()->id, $data),
        ]);
    }

    public function archive(Request $request, int $conversationId)
    {
        $this->messaging->archive($conversationId, (int) $request->user()->id);
        return response()->json(['message' => 'Conversa arquivada.']);
    }

    private function normalizedMetadata(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        abort_unless(is_array($decoded), 422, 'Metadados inválidos.');
        return $decoded;
    }
}
