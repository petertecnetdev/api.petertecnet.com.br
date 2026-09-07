<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Domain\Messaging\Services\MessagingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function index(Request $request)
    {
        $data = $this->messaging->conversations((int) $request->user()->id, trim((string) $request->query('q', '')));
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

    public function showConversation(Request $request, int $conversationId)
    {
        return response()->json(['data' => $this->messaging->conversation($conversationId, (int) $request->user()->id)]);
    }

    public function messages(Request $request, int $conversationId)
    {
        return response()->json($this->messaging->messages($conversationId, (int) $request->user()->id, max(0, (int) $request->query('before', 0))));
    }

    public function send(Request $request, int $conversationId)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'reply_to_id' => ['nullable', 'integer']]);
        return response()->json(['data' => $this->messaging->send($conversationId, (int) $request->user()->id, $data['body'], isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null)], 201);
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
}
