<?php

namespace App\Domain\Messaging\Services;

use App\Events\AppConversationRead;
use App\Events\AppMessageCreated;
use App\Models\AppConversation;
use App\Models\AppMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppMessagingService
{
    public function conversations(int $userId, int $applicationId, int $perPage = 25): array
    {
        $perPage = min(max($perPage, 1), 50);

        $conversations = AppConversation::query()
            ->where('application_id', $applicationId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->with([
                'users:id,first_name,last_name,user_name,email,avatar,city,uf',
                'lastMessage.sender:id,first_name,last_name,user_name,avatar',
            ])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $conversations->setCollection(
            $conversations->getCollection()->map(
                fn (AppConversation $conversation) => $this->conversationPayload($conversation, $userId)
            )
        );

        return [
            'conversations' => $conversations,
            'unread_count' => $this->totalUnread($userId, $applicationId),
        ];
    }

    public function unreadCount(int $userId, int $applicationId): int
    {
        return $this->totalUnread($userId, $applicationId);
    }

    public function people(int $userId, int $applicationId, string $term = '', int $perPage = 20): Collection
    {
        $perPage = min(max($perPage, 1), 30);
        $term = trim($term);

        $query = User::query()
            ->where('users.id', '<>', $userId)
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

        return $query
            ->select(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'city', 'uf', 'about'])
            ->orderByRaw("CASE WHEN avatar IS NULL OR avatar = '' THEN 1 ELSE 0 END")
            ->orderBy('first_name')
            ->limit($perPage)
            ->get()
            ->map(fn (User $person) => $this->personPayload($person));
    }

    public function createDirect(int $userId, int $recipientId, int $applicationId): array
    {
        $recipient = User::query()
            ->whereKey($recipientId)
            ->whereHas('applications', fn ($query) => $query->where('applications.id', $applicationId))
            ->first();

        if (! $recipient || $recipientId === $userId) {
            throw ValidationException::withMessages([
                'recipient_user_id' => ['Este usuário não está disponível para conversa nesta aplicação.'],
            ]);
        }

        $ids = [$userId, $recipientId];
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

        return $this->conversationPayload($conversation, $userId);
    }

    public function messages(int $userId, int $applicationId, int $conversationId, int $perPage = 40): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $perPage = min(max($perPage, 1), 100);

        $messages = AppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->with('sender:id,first_name,last_name,user_name,avatar')
            ->orderByDesc('id')
            ->paginate($perPage);

        $messages->setCollection(
            $messages->getCollection()
                ->reverse()
                ->values()
                ->map(fn (AppMessage $message) => $this->messagePayload($message))
        );

        return [
            'conversation' => $this->conversationPayload(
                $conversation->loadMissing(['users', 'lastMessage.sender']),
                $userId
            ),
            'messages' => $messages,
        ];
    }

    public function send(int $userId, int $applicationId, int $conversationId, string $body): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['Digite uma mensagem antes de enviar.']]);
        }

        $message = DB::transaction(function () use ($conversation, $userId, $body) {
            $message = AppMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $userId,
                'type' => 'text',
                'body' => $body,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            DB::table('app_conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->update([
                    'unread_count' => 0,
                    'last_read_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('app_conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '<>', $userId)
                ->update([
                    'unread_count' => DB::raw('unread_count + 1'),
                    'updated_at' => now(),
                ]);

            return $message;
        });

        $message->load('sender:id,first_name,last_name,user_name,avatar');
        $participantIds = $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        broadcast(new AppMessageCreated($message, $participantIds, (int) $conversation->application_id));

        return $this->messagePayload($message);
    }

    public function markRead(int $userId, int $applicationId, int $conversationId): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $readAt = now();

        DB::table('app_conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->update([
                'unread_count' => 0,
                'last_read_at' => $readAt,
                'updated_at' => $readAt,
            ]);

        $participantIds = $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        broadcast(new AppConversationRead(
            (int) $conversation->id,
            $userId,
            $participantIds,
            (int) $conversation->application_id,
            $readAt->toISOString(),
        ));

        return [
            'read' => true,
            'read_at' => $readAt->toISOString(),
            'unread_count' => $this->totalUnread($userId, (int) $conversation->application_id),
        ];
    }

    private function authorizedConversation(int $applicationId, int $conversationId, int $userId): AppConversation
    {
        return AppConversation::query()
            ->whereKey($conversationId)
            ->where('application_id', $applicationId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->firstOrFail();
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
            'name' => $name !== '' ? $name : ($person->user_name ?: 'Usuário'),
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
