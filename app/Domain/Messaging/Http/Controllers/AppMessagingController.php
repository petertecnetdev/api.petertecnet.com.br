<?php

namespace App\Domain\Messaging\Http\Controllers;

use App\Events\AppConversationRead;
use App\Events\AppMessageCreated;
use App\Http\Controllers\Controller;
use App\Models\AppConversation;
use App\Models\AppMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppMessagingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $applicationId = $this->applicationId($request);
        $perPage = min(max((int) $request->query('per_page', 25), 1), 50);

        $conversations = AppConversation::query()
            ->where('application_id', $applicationId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
            ->with([
                'users:id,first_name,last_name,user_name,email,avatar,city,uf',
                'lastMessage.sender:id,first_name,last_name,user_name,avatar',
            ])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $conversations->setCollection(
            $conversations->getCollection()->map(fn (AppConversation $conversation) => $this->conversationPayload($conversation, (int) $user->id))
        );

        return response()->json([
            'conversations' => $conversations,
            'unread_count' => $this->totalUnread((int) $user->id, $applicationId),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->totalUnread((int) $request->user('api')->id, $this->applicationId($request)),
        ]);
    }

    public function people(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $applicationId = $this->applicationId($request);
        $term = trim((string) $request->query('q', ''));
        $perPage = min(max((int) $request->query('per_page', 20), 1), 30);

        $query = User::query()
            ->where('users.id', '<>', $user->id)
            ->whereHas('applications', fn ($appQuery) => $appQuery->where('applications.id', $applicationId));

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $like = '%'.$escaped.'%';
            $query->where(function ($userQuery) use ($like) {
                $userQuery->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $people = $query
            ->select(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'city', 'uf', 'about'])
            ->orderByRaw("CASE WHEN avatar IS NULL OR avatar = '' THEN 1 ELSE 0 END")
            ->orderBy('first_name')
            ->limit($perPage)
            ->get()
            ->map(fn (User $person) => $this->personPayload($person));

        return response()->json(['people' => $people]);
    }

    public function createDirect(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $applicationId = $this->applicationId($request);
        $payload = $request->validate([
            'recipient_user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([(int) $user->id])],
        ]);
        $recipientId = (int) $payload['recipient_user_id'];

        $recipient = User::query()
            ->whereKey($recipientId)
            ->whereHas('applications', fn ($query) => $query->where('applications.id', $applicationId))
            ->first();

        if (! $recipient) {
            return response()->json([
                'message' => 'Este usuário não está disponível para conversa nesta aplicação.',
                'code' => 'MESSAGING_RECIPIENT_NOT_AVAILABLE',
            ], 422);
        }

        $ids = [(int) $user->id, $recipientId];
        sort($ids, SORT_NUMERIC);
        $directKey = implode(':', $ids);

        $conversation = DB::transaction(function () use ($applicationId, $directKey, $ids) {
            $conversation = AppConversation::query()->firstOrCreate(
                ['application_id' => $applicationId, 'direct_key' => $directKey],
                ['type' => 'direct']
            );

            foreach ($ids as $participantId) {
                DB::table('app_conversation_participants')->updateOrInsert(
                    ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                    [
                        'joined_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            return $conversation;
        });

        $conversation->load([
            'users:id,first_name,last_name,user_name,email,avatar,city,uf',
            'lastMessage.sender:id,first_name,last_name,user_name,avatar',
        ]);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation, (int) $user->id),
        ], 201);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user('api');
        $conversation = $this->authorizedConversation($request, $conversationId, (int) $user->id);
        $perPage = min(max((int) $request->query('per_page', 40), 1), 100);

        $messages = AppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->with('sender:id,first_name,last_name,user_name,avatar')
            ->orderByDesc('id')
            ->paginate($perPage);

        $messages->setCollection(
            $messages->getCollection()->reverse()->values()->map(fn (AppMessage $message) => $this->messagePayload($message))
        );

        return response()->json([
            'conversation' => $this->conversationPayload($conversation->loadMissing(['users', 'lastMessage.sender']), (int) $user->id),
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user('api');
        $conversation = $this->authorizedConversation($request, $conversationId, (int) $user->id);
        $payload = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'type' => ['sometimes', 'string', Rule::in(['text'])],
        ]);
        $body = trim((string) $payload['body']);

        if ($body === '') {
            return response()->json(['message' => 'Digite uma mensagem antes de enviar.'], 422);
        }

        $message = DB::transaction(function () use ($conversation, $user, $body) {
            $message = AppMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $user->id,
                'type' => 'text',
                'body' => $body,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            DB::table('app_conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->update(['unread_count' => 0, 'last_read_at' => now(), 'updated_at' => now()]);

            DB::table('app_conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '<>', $user->id)
                ->update([
                    'unread_count' => DB::raw('unread_count + 1'),
                    'updated_at' => now(),
                ]);

            return $message;
        });

        $message->load('sender:id,first_name,last_name,user_name,avatar');
        $participantIds = $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        broadcast(new AppMessageCreated($message, $participantIds, (int) $conversation->application_id));

        return response()->json(['message' => $this->messagePayload($message)], 201);
    }

    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user('api');
        $conversation = $this->authorizedConversation($request, $conversationId, (int) $user->id);
        $readAt = now();

        DB::table('app_conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['unread_count' => 0, 'last_read_at' => $readAt, 'updated_at' => $readAt]);

        $participantIds = $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        broadcast(new AppConversationRead(
            (int) $conversation->id,
            (int) $user->id,
            $participantIds,
            (int) $conversation->application_id,
            $readAt->toISOString(),
        ));

        return response()->json([
            'read' => true,
            'read_at' => $readAt->toISOString(),
            'unread_count' => $this->totalUnread((int) $user->id, (int) $conversation->application_id),
        ]);
    }

    private function authorizedConversation(Request $request, int $conversationId, int $userId): AppConversation
    {
        return AppConversation::query()
            ->whereKey($conversationId)
            ->where('application_id', $this->applicationId($request))
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->firstOrFail();
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }

    private function totalUnread(int $userId, int $applicationId): int
    {
        return (int) DB::table('app_conversation_participants as participant')
            ->join('app_conversations as conversation', 'conversation.id', '=', 'participant.conversation_id')
            ->where('participant.user_id', $userId)
            ->where('conversation.application_id', $applicationId)
            ->sum('participant.unread_count');
    }

    private function conversationPayload(AppConversation $conversation, int $currentUserId): array
    {
        $users = $conversation->users;
        $current = $users->firstWhere('id', $currentUserId);
        $others = $users->where('id', '<>', $currentUserId)->values();

        return [
            'id' => (int) $conversation->id,
            'type' => $conversation->type,
            'application_id' => (int) $conversation->application_id,
            'last_message_at' => optional($conversation->last_message_at)->toISOString(),
            'unread_count' => (int) ($current?->pivot?->unread_count ?? 0),
            'last_read_at' => $current?->pivot?->last_read_at,
            'participants' => $users->map(fn (User $person) => array_merge($this->personPayload($person), [
                'last_read_at' => $person->pivot?->last_read_at,
            ]))->values(),
            'other_users' => $others->map(fn (User $person) => $this->personPayload($person))->values(),
            'last_message' => $conversation->lastMessage ? $this->messagePayload($conversation->lastMessage) : null,
        ];
    }

    private function messagePayload(AppMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_user_id' => (int) $message->sender_user_id,
            'type' => $message->type,
            'body' => $message->body,
            'metadata' => $message->metadata,
            'created_at' => optional($message->created_at)->toISOString(),
            'edited_at' => optional($message->edited_at)->toISOString(),
            'sender' => $message->sender ? $this->personPayload($message->sender) : null,
        ];
    }

    private function personPayload(User $person): array
    {
        $name = trim(implode(' ', array_filter([$person->first_name, $person->last_name])));

        return [
            'id' => (int) $person->id,
            'name' => $name !== '' ? $name : ($person->user_name ?: 'Usuário Cutinapp'),
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'user_name' => $person->user_name,
            'avatar' => $person->avatar,
            'city' => $person->city,
            'uf' => $person->uf,
            'about' => $person->about,
        ];
    }
}
