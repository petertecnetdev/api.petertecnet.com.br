<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Events\ConversationChanged;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class MessagingService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function conversations(int $userId, string $search = '', string $filter = 'all'): array
    {
        $rows = DB::table('conversation_participants as cp')
            ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $userId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('c.deleted_at')
            ->when($filter === 'archived', fn ($query) => $query->whereNotNull('cp.archived_at'))
            ->when($filter !== 'archived', fn ($query) => $query->whereNull('cp.archived_at'))
            ->orderByRaw('CASE WHEN cp.pinned_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('cp.pinned_at')
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))
            ->limit(100)
            ->get(['c.*', 'cp.last_read_at', 'cp.marked_unread_at', 'cp.muted_until', 'cp.pinned_at', 'cp.archived_at']);

        return $rows->map(function ($conversation) use ($userId, $search, $filter) {
            $other = $this->otherParticipant((int) $conversation->id, $userId);
            if (! $other) {
                return null;
            }

            $haystack = mb_strtolower($this->displayName($other).' '.$other->user_name);
            $needle = mb_strtolower(trim($search));
            if ($needle !== '' && ! str_contains($haystack, ltrim($needle, '@'))) {
                return null;
            }

            $lastMessage = DB::table('messages')->where('conversation_id', $conversation->id)->whereNull('deleted_at')->orderByDesc('id')->first();
            $unread = $this->unreadCount((int) $conversation->id, $userId, $conversation->last_read_at, $conversation->created_at);
            if ($conversation->marked_unread_at) {
                $unread = max(1, $unread);
            }
            if ($filter === 'unread' && $unread === 0) {
                return null;
            }

            return [
                'id' => (int) $conversation->id,
                'type' => $conversation->type,
                'user' => $this->userPayload($other),
                'last_message' => $lastMessage ? $this->messagePayload($lastMessage, $userId) : null,
                'unread_count' => $unread,
                'muted_until' => $conversation->muted_until,
                'pinned_at' => $conversation->pinned_at,
                'archived_at' => $conversation->archived_at,
                'updated_at' => $conversation->last_message_at ?: $conversation->updated_at,
            ];
        })->filter()->values()->all();
    }

    public function people(int $userId, string $query): array
    {
        $query = trim(ltrim($query, '@'));
        if (mb_strlen($query) < 2) {
            return [];
        }

        return User::query()
            ->where('id', '<>', $userId)
            ->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))
            ->where(function ($builder) use ($query) {
                $builder->where('user_name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%")
                    ->orWhere('email', '=', mb_strtolower($query));
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get()
            ->map(fn (User $user) => $this->userPayload($user))
            ->values()->all();
    }

    public function openDirect(int $userId, int $targetId): array
    {
        abort_unless(
            User::query()->whereKey($targetId)->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))->exists(),
            422,
            'Usuário não pertence a esta aplicação.'
        );

        [$one, $two] = $userId < $targetId ? [$userId, $targetId] : [$targetId, $userId];
        $directKey = hash('sha256', $one.':'.$two);
        $conversationId = null;

        DB::transaction(function () use ($userId, $targetId, $directKey, &$conversationId) {
            $conversation = DB::table('conversations')->where('app_id', $this->context->id())->where('direct_key', $directKey)->whereNull('deleted_at')->first();
            if (! $conversation) {
                $conversationId = DB::table('conversations')->insertGetId([
                    'app_id' => $this->context->id(), 'type' => 'direct', 'created_by' => $userId,
                    'direct_key' => $directKey, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ([$userId, $targetId] as $participantId) {
                    DB::table('conversation_participants')->insert([
                        'conversation_id' => $conversationId, 'user_id' => $participantId,
                        'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } else {
                $conversationId = (int) $conversation->id;
                DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
                    ->update(['archived_at' => null, 'updated_at' => now()]);
            }
        });

        return $this->conversation((int) $conversationId, $userId);
    }

    public function conversation(int $conversationId, int $userId): array
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        $participant = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->first();
        $other = $this->otherParticipant($conversationId, $userId);

        return [
            'id' => (int) $conversation->id,
            'type' => $conversation->type,
            'user' => $other ? $this->userPayload($other) : null,
            'muted_until' => $participant?->muted_until,
            'pinned_at' => $participant?->pinned_at,
            'archived_at' => $participant?->archived_at,
        ];
    }

    public function messages(int $conversationId, int $userId, int $before = 0, string $search = ''): array
    {
        $this->ownedConversation($conversationId, $userId);
        $query = DB::table('messages')->where('conversation_id', $conversationId)->whereNull('deleted_at')->orderByDesc('id')->limit(50);
        if ($before > 0) {
            $query->where('id', '<', $before);
        }
        if ($search !== '') {
            $query->where('body', 'like', '%'.trim($search).'%');
        }

        $messages = $query->get()->reverse()->values();
        if ($search === '') {
            $this->markDelivered($conversationId, $userId);
            $this->markRead($conversationId, $userId);
        }

        return [
            'data' => $messages->map(fn ($message) => $this->messagePayload($message, $userId))->values()->all(),
            'meta' => [
                'has_more' => $messages->count() === 50,
                'next_before' => $messages->isNotEmpty() ? (int) $messages->first()->id : null,
            ],
        ];
    }

    public function send(
        int $conversationId,
        int $userId,
        ?string $body,
        ?int $replyToId = null,
        string $type = 'text',
        ?array $metadata = null,
        array $attachments = [],
    ): array {
        $this->ownedConversation($conversationId, $userId);
        $body = trim((string) $body);
        abort_if($body === '' && $attachments === [] && $metadata === null, 422, 'A mensagem está vazia.');

        $replyTo = $replyToId ? DB::table('messages')->where('id', $replyToId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->value('id') : null;

        $id = DB::transaction(function () use ($conversationId, $userId, $body, $replyTo, $type, $metadata, $attachments) {
            $now = now();
            $id = DB::table('messages')->insertGetId([
                'conversation_id' => $conversationId,
                'sender_user_id' => $userId,
                'reply_to_id' => $replyTo,
                'type' => $type,
                'body' => $body !== '' ? $body : null,
                'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($attachments as $attachment) {
                DB::table('message_attachments')->insert([
                    'message_id' => $id,
                    'kind' => $attachment['kind'] ?? 'file',
                    'disk' => $attachment['disk'] ?? 'public',
                    'path' => $attachment['path'],
                    'original_name' => $attachment['original_name'] ?? null,
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'size_bytes' => $attachment['size_bytes'] ?? null,
                    'duration_ms' => $attachment['duration_ms'] ?? null,
                    'metadata' => isset($attachment['metadata']) ? json_encode($attachment['metadata']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $recipientIds = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', '<>', $userId)->pluck('user_id');
            foreach ($recipientIds as $recipientId) {
                DB::table('message_receipts')->updateOrInsert(
                    ['message_id' => $id, 'user_id' => (int) $recipientId],
                    ['updated_at' => $now, 'created_at' => $now]
                );
            }

            DB::table('conversations')->where('id', $conversationId)->update(['last_message_at' => $now, 'updated_at' => $now]);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)
                ->update(['archived_at' => null, 'updated_at' => $now]);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
                ->update(['last_read_at' => $now, 'marked_unread_at' => null, 'updated_at' => $now]);

            return $id;
        });

        $payload = $this->messagePayload(DB::table('messages')->where('id', $id)->first(), $userId);
        event(new ConversationChanged($conversationId, 'message.created', $payload));

        return $payload;
    }

    public function editMessage(int $messageId, int $userId, string $body): array
    {
        $message = DB::table('messages')->where('id', $messageId)->where('sender_user_id', $userId)->whereNull('deleted_at')->first();
        abort_unless($message, 404, 'Mensagem não encontrada.');
        $this->ownedConversation((int) $message->conversation_id, $userId);

        DB::table('messages')->where('id', $messageId)->update(['body' => trim($body), 'edited_at' => now(), 'updated_at' => now()]);
        $payload = $this->messagePayload(DB::table('messages')->where('id', $messageId)->first(), $userId);
        event(new ConversationChanged((int) $message->conversation_id, 'message.updated', $payload));

        return $payload;
    }

    public function deleteMessage(int $messageId, int $userId): void
    {
        $message = DB::table('messages')->where('id', $messageId)->where('sender_user_id', $userId)->whereNull('deleted_at')->first();
        abort_unless($message, 404, 'Mensagem não encontrada.');
        $this->ownedConversation((int) $message->conversation_id, $userId);
        DB::table('messages')->where('id', $messageId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        event(new ConversationChanged((int) $message->conversation_id, 'message.deleted', ['id' => $messageId]));
    }

    public function toggleReaction(int $messageId, int $userId, string $reaction): array
    {
        $message = DB::table('messages')->where('id', $messageId)->whereNull('deleted_at')->first();
        abort_unless($message, 404, 'Mensagem não encontrada.');
        $this->ownedConversation((int) $message->conversation_id, $userId);

        $existing = DB::table('message_reactions')->where('message_id', $messageId)->where('user_id', $userId)->where('reaction', $reaction)->first();
        if ($existing) {
            DB::table('message_reactions')->where('id', $existing->id)->delete();
        } else {
            DB::table('message_reactions')->insert([
                'message_id' => $messageId, 'user_id' => $userId, 'reaction' => $reaction,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $reactions = $this->reactionsPayload($messageId, $userId);
        event(new ConversationChanged((int) $message->conversation_id, 'message.reactions', ['id' => $messageId, 'reactions' => $reactions]));

        return $reactions;
    }

    public function markDelivered(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $messageIds = DB::table('messages')->where('conversation_id', $conversationId)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')->pluck('id');
        if ($messageIds->isEmpty()) {
            return;
        }
        DB::table('message_receipts')->where('user_id', $userId)->whereIn('message_id', $messageIds)->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $now = now();
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
            ->update(['last_read_at' => $now, 'marked_unread_at' => null, 'updated_at' => $now]);

        $messageIds = DB::table('messages')->where('conversation_id', $conversationId)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')->pluck('id');
        if ($messageIds->isNotEmpty()) {
            DB::table('message_receipts')->where('user_id', $userId)->whereIn('message_id', $messageIds)
                ->update(['delivered_at' => DB::raw('COALESCE(delivered_at, CURRENT_TIMESTAMP)'), 'read_at' => $now, 'updated_at' => $now]);
        }
        event(new ConversationChanged($conversationId, 'conversation.read', ['user_id' => $userId, 'read_at' => $now->toIso8601String()]));
    }

    public function updateConversationState(int $conversationId, int $userId, array $state): array
    {
        $this->ownedConversation($conversationId, $userId);
        $updates = ['updated_at' => now()];
        if (array_key_exists('pinned', $state)) {
            $updates['pinned_at'] = $state['pinned'] ? now() : null;
        }
        if (array_key_exists('muted', $state)) {
            $updates['muted_until'] = $state['muted'] ? now()->addYears(10) : null;
        }
        if (array_key_exists('unread', $state)) {
            $updates['marked_unread_at'] = $state['unread'] ? now() : null;
        }
        if (array_key_exists('archived', $state)) {
            $updates['archived_at'] = $state['archived'] ? now() : null;
        }
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->update($updates);
        return $this->conversation($conversationId, $userId);
    }

    public function archive(int $conversationId, int $userId): void
    {
        $this->updateConversationState($conversationId, $userId, ['archived' => true]);
    }

    private function ownedConversation(int $conversationId, int $userId): object
    {
        $conversation = DB::table('conversations as c')->join('conversation_participants as cp', 'cp.conversation_id', '=', 'c.id')
            ->where('c.id', $conversationId)->where('c.app_id', $this->context->id())->where('cp.user_id', $userId)->whereNull('c.deleted_at')
            ->select('c.*')->first();
        abort_unless($conversation, 404, 'Conversa não encontrada.');
        return $conversation;
    }

    private function otherParticipant(int $conversationId, int $userId): ?User
    {
        $otherId = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', '<>', $userId)->value('user_id');
        return $otherId ? User::find($otherId) : null;
    }

    private function unreadCount(int $conversationId, int $userId, $lastReadAt, $createdAt): int
    {
        return DB::table('messages')->where('conversation_id', $conversationId)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')
            ->when($lastReadAt, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->when(! $lastReadAt, fn ($query) => $query->where('created_at', '>=', $createdAt))->count();
    }

    private function userPayload(User $user): array
    {
        return ['id' => (int) $user->id, 'user_name' => $user->user_name, 'name' => $this->displayName($user), 'avatar' => $user->avatar];
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->first_name.' '.(string) $user->last_name);
        return $name !== '' ? $name : ((string) $user->user_name ?: 'Usuário');
    }

    private function messagePayload(object $message, int $viewerId = 0): array
    {
        $metadata = $message->metadata ? json_decode((string) $message->metadata, true) : null;
        $reply = null;
        if ($message->reply_to_id) {
            $source = DB::table('messages')->where('id', $message->reply_to_id)->first();
            if ($source) {
                $reply = ['id' => (int) $source->id, 'sender_user_id' => (int) $source->sender_user_id, 'body' => $source->body, 'type' => $source->type];
            }
        }

        $attachments = DB::table('message_attachments')->where('message_id', $message->id)->orderBy('id')->get()->map(function ($attachment) {
            return [
                'id' => (int) $attachment->id,
                'kind' => $attachment->kind,
                'url' => '/storage/'.ltrim($attachment->path, '/'),
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes ? (int) $attachment->size_bytes : null,
                'duration_ms' => $attachment->duration_ms ? (int) $attachment->duration_ms : null,
            ];
        })->all();

        $status = 'sent';
        if ((int) $message->sender_user_id === $viewerId) {
            $receipt = DB::table('message_receipts')->where('message_id', $message->id)->orderByDesc('read_at')->orderByDesc('delivered_at')->first();
            if ($receipt?->read_at) {
                $status = 'read';
            } elseif ($receipt?->delivered_at) {
                $status = 'delivered';
            }
        }

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_user_id' => (int) $message->sender_user_id,
            'reply_to_id' => $message->reply_to_id ? (int) $message->reply_to_id : null,
            'reply_to' => $reply,
            'type' => $message->type,
            'body' => $message->body,
            'metadata' => $metadata,
            'attachments' => $attachments,
            'reactions' => $this->reactionsPayload((int) $message->id, $viewerId),
            'status' => $status,
            'edited_at' => $message->edited_at,
            'created_at' => $message->created_at,
        ];
    }

    private function reactionsPayload(int $messageId, int $viewerId): array
    {
        return DB::table('message_reactions')
            ->where('message_id', $messageId)
            ->select('reaction', DB::raw('COUNT(*) as total'), DB::raw('MAX(CASE WHEN user_id = '.(int) $viewerId.' THEN 1 ELSE 0 END) as mine'))
            ->groupBy('reaction')
            ->orderBy('reaction')
            ->get()
            ->map(fn ($row) => ['reaction' => $row->reaction, 'count' => (int) $row->total, 'mine' => (bool) $row->mine])
            ->all();
    }
}
