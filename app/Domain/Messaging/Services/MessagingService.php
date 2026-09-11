<?php

namespace App\Domain\Messaging\Services;

use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class MessagingService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function conversations(int $userId, string $search = ''): array
    {
        $rows = DB::table('conversation_participants as cp')->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $userId)->where('c.app_id', $this->context->id())->whereNull('c.deleted_at')->whereNull('cp.archived_at')
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))->limit(100)->get(['c.*', 'cp.last_read_at', 'cp.muted_until']);

        return $rows->map(function ($conversation) use ($userId, $search) {
            $other = $this->otherParticipant((int) $conversation->id, $userId);
            if (! $other || ($search !== '' && stripos($this->displayName($other).' '.$other->user_name, $search) === false)) {
                return null;
            }

            $lastMessage = DB::table('messages')->where('conversation_id', $conversation->id)->whereNull('deleted_at')->orderByDesc('id')->first();
            $unread = DB::table('messages')->where('conversation_id', $conversation->id)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')
                ->when($conversation->last_read_at, fn ($query) => $query->where('created_at', '>', $conversation->last_read_at))
                ->when(! $conversation->last_read_at, fn ($query) => $query->where('created_at', '>=', $conversation->created_at))->count();

            return [
                'id' => (int) $conversation->id,
                'type' => $conversation->type,
                'user' => $this->userPayload($other),
                'last_message' => $lastMessage ? $this->messagePayload($lastMessage) : null,
                'unread_count' => $unread,
                'muted_until' => $conversation->muted_until,
                'updated_at' => $conversation->last_message_at ?: $conversation->updated_at,
            ];
        })->filter()->values()->all();
    }

    public function people(int $userId, string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return User::query()
            ->where('id', '<>', $userId)
            ->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))
            ->where(function ($builder) use ($query) {
                $builder->where('user_name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->orderBy('first_name')->limit(20)->get()
            ->map(fn (User $user) => $this->userPayload($user))->values()->all();
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
        $other = $this->otherParticipant($conversationId, $userId);
        return ['id' => (int) $conversation->id, 'type' => $conversation->type, 'user' => $other ? $this->userPayload($other) : null];
    }

    public function messages(int $conversationId, int $userId, int $before = 0): array
    {
        $this->ownedConversation($conversationId, $userId);
        $query = DB::table('messages')->where('conversation_id', $conversationId)->whereNull('deleted_at')->orderByDesc('id')->limit(50);
        if ($before > 0) {
            $query->where('id', '<', $before);
        }
        $messages = $query->get()->reverse()->values();
        $this->markRead($conversationId, $userId);
        return [
            'data' => $messages->map(fn ($message) => $this->messagePayload($message))->values()->all(),
            'meta' => ['has_more' => $messages->count() === 50, 'next_before' => $messages->isNotEmpty() ? (int) $messages->first()->id : null],
        ];
    }

    public function send(int $conversationId, int $userId, string $body, ?int $replyToId = null): array
    {
        $this->ownedConversation($conversationId, $userId);
        $replyTo = $replyToId ? DB::table('messages')->where('id', $replyToId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->value('id') : null;

        $id = DB::transaction(function () use ($conversationId, $userId, $body, $replyTo) {
            $now = now();
            $id = DB::table('messages')->insertGetId([
                'conversation_id' => $conversationId, 'sender_user_id' => $userId, 'reply_to_id' => $replyTo,
                'type' => 'text', 'body' => trim($body), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('conversations')->where('id', $conversationId)->update(['last_message_at' => $now, 'updated_at' => $now]);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
                ->update(['last_read_at' => $now, 'archived_at' => null, 'updated_at' => $now]);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', '<>', $userId)
                ->update(['archived_at' => null, 'updated_at' => $now]);
            return $id;
        });

        $message = DB::table('messages')->where('id', $id)->first();
        $this->notifyRecipient($conversationId, $userId, trim($body));

        return $this->messagePayload($message);
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
            ->update(['last_read_at' => now(), 'updated_at' => now()]);
    }

    public function archive(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)
            ->update(['archived_at' => now(), 'updated_at' => now()]);
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

    private function userPayload(User $user): array
    {
        return ['id' => (int) $user->id, 'user_name' => $user->user_name, 'name' => $this->displayName($user), 'avatar' => $user->avatar];
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->first_name.' '.(string) $user->last_name);
        return $name !== '' ? $name : ((string) $user->user_name ?: 'Usuário');
    }

    private function notifyRecipient(int $conversationId, int $senderUserId, string $body): void
    {
        $recipient = $this->otherParticipant($conversationId, $senderUserId);
        $sender = User::query()->find($senderUserId);

        if (! $recipient || ! $sender) {
            return;
        }

        $preview = trim((string) preg_replace('/\s+/', ' ', $body));
        $preview = $preview !== '' ? Str::limit($preview, 180) : 'Você recebeu uma nova mensagem.';

        try {
            $this->notifications->sendToUser($this->context->id(), (int) $recipient->id, [
                'type' => 'direct_message_received',
                'title' => $this->displayName($sender).' enviou uma mensagem',
                'message' => $preview,
                'reference_type' => 'conversation',
                'reference_id' => $conversationId,
                'reference_url' => '/messages?user='.$senderUserId,
                'data' => [
                    'conversation_id' => $conversationId,
                    'sender_user_id' => $senderUserId,
                    'action_label' => 'Responder agora',
                    'preview' => $preview,
                ],
                'send_email' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao notificar destinatário sobre nova mensagem.', [
                'app_id' => $this->context->id(),
                'conversation_id' => $conversationId,
                'sender_user_id' => $senderUserId,
                'recipient_user_id' => $recipient->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function messagePayload(object $message): array
    {
        return [
            'id' => (int) $message->id, 'conversation_id' => (int) $message->conversation_id,
            'sender_user_id' => (int) $message->sender_user_id, 'reply_to_id' => $message->reply_to_id ? (int) $message->reply_to_id : null,
            'type' => $message->type, 'body' => $message->body, 'edited_at' => $message->edited_at, 'created_at' => $message->created_at,
        ];
    }
}
