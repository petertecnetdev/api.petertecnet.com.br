<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Domain\Messaging\Events\MessagingRealtimeEvent;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessagingService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ManagedFileStorageService $files,
        private readonly AppNotificationService $notifications,
        private readonly MessageEngagementService $engagement,
    ) {}

    public function conversations(int $userId, string $search = '', array $filters = []): array
    {
        $rows = DB::table('conversation_participants as cp')
            ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $userId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('c.deleted_at')
            ->when(empty($filters['archived']), fn ($query) => $query->whereNull('cp.archived_at'))
            ->when(! empty($filters['archived']), fn ($query) => $query->whereNotNull('cp.archived_at'))
            ->when(! empty($filters['pinned']), fn ($query) => $query->whereNotNull('cp.pinned_at'))
            ->when(! empty($filters['requests']),
                fn ($query) => $query->where('cp.request_state', 'pending'),
                fn ($query) => $query->where('cp.request_state', 'accepted')
            )
            ->orderByRaw('CASE WHEN cp.pinned_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('cp.pinned_at')
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))
            ->limit(150)
            ->get(['c.*', 'cp.last_read_at', 'cp.last_delivered_at', 'cp.muted_until', 'cp.pinned_at as participant_pinned_at', 'cp.notification_level', 'cp.request_state']);

        return $rows->map(function ($conversation) use ($userId, $search, $filters) {
            $payload = $this->conversationPayload($conversation, $userId);
            $haystack = trim(implode(' ', [
                $payload['title'] ?? '',
                $payload['user']['name'] ?? '',
                $payload['user']['user_name'] ?? '',
            ]));

            if ($search !== '' && stripos($haystack, $search) === false) {
                return null;
            }

            if (! empty($filters['unread']) && ($payload['unread_count'] ?? 0) < 1) {
                return null;
            }

            return $payload;
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
                    ->orWhere('last_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get()
            ->map(fn (User $user) => $this->userPayload($user, true))
            ->values()
            ->all();
    }

    public function openDirect(int $userId, int $targetId): array
    {
        abort_if($this->blockedEitherWay($userId, $targetId), 403, 'Não é possível iniciar uma conversa com este usuário.');

        abort_unless(
            User::query()->whereKey($targetId)->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))->exists(),
            422,
            'Usuário não pertence a esta aplicação.'
        );

        $targetSettings = $this->settings($targetId);
        abort_if(($targetSettings['allow_messages_from'] ?? 'everyone') === 'none', 403, 'Este usuário não está aceitando novas mensagens.');
        $targetRequestState = ($targetSettings['allow_messages_from'] ?? 'everyone') === 'requests' ? 'pending' : 'accepted';

        [$one, $two] = $userId < $targetId ? [$userId, $targetId] : [$targetId, $userId];
        $directKey = hash('sha256', $one.':'.$two);
        $conversationId = null;

        DB::transaction(function () use ($userId, $targetId, $directKey, $targetRequestState, &$conversationId) {
            $conversation = DB::table('conversations')
                ->where('app_id', $this->context->id())
                ->where('direct_key', $directKey)
                ->whereNull('deleted_at')
                ->first();

            if (! $conversation) {
                $conversationId = DB::table('conversations')->insertGetId([
                    'app_id' => $this->context->id(),
                    'type' => 'direct',
                    'created_by' => $userId,
                    'direct_key' => $directKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ([$userId, $targetId] as $participantId) {
                    DB::table('conversation_participants')->insert([
                        'conversation_id' => $conversationId,
                        'user_id' => $participantId,
                        'role' => $participantId === $userId ? 'owner' : 'member',
                        'request_state' => $participantId === $targetId ? $targetRequestState : 'accepted',
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                $conversationId = (int) $conversation->id;
                DB::table('conversation_participants')
                    ->where('conversation_id', $conversationId)
                    ->where('user_id', $userId)
                    ->update(['archived_at' => null, 'updated_at' => now()]);
            }
        });

        return $this->conversation((int) $conversationId, $userId);
    }

    public function acceptRequest(int $conversationId, int $userId): array
    {
        $this->ownedConversation($conversationId, $userId);
        $participant = $this->participantRow($conversationId, $userId);
        abort_unless($participant->request_state === 'pending', 422, 'Esta conversa não é uma solicitação pendente.');

        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update([
                'request_state' => 'accepted',
                'archived_at' => null,
                'updated_at' => now(),
            ]);

        $payload = $this->conversation($conversationId, $userId);
        $this->broadcast($conversationId, 'messaging.conversation.updated', ['conversation' => $payload], $this->participantIds($conversationId));

        return $payload;
    }

    public function rejectRequest(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $participant = $this->participantRow($conversationId, $userId);
        abort_unless($participant->request_state === 'pending', 422, 'Esta conversa não é uma solicitação pendente.');

        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update([
                'request_state' => 'rejected',
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

        $this->broadcast($conversationId, 'messaging.conversation.updated', ['request_rejected_by' => $userId], $this->participantIds($conversationId));
    }

    public function createGroup(int $userId, string $title, array $participantIds): array
    {
        $participantIds = collect($participantIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $userId)
            ->unique()
            ->values();

        abort_if($participantIds->isEmpty(), 422, 'Adicione pelo menos uma pessoa ao grupo.');
        abort_if($participantIds->count() > 99, 422, 'O grupo pode ter no máximo 100 participantes.');

        $validIds = User::query()
            ->whereIn('id', $participantIds)
            ->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_unless($validIds->count() === $participantIds->count(), 422, 'Um ou mais usuários não pertencem a esta aplicação.');

        $conversationId = DB::transaction(function () use ($userId, $title, $validIds) {
            $conversationId = DB::table('conversations')->insertGetId([
                'app_id' => $this->context->id(),
                'type' => 'group',
                'created_by' => $userId,
                'title' => trim($title) ?: 'Novo grupo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $all = collect([$userId])->merge($validIds)->unique();
            foreach ($all as $participantId) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversationId,
                    'user_id' => $participantId,
                    'role' => (int) $participantId === $userId ? 'owner' : 'member',
                    'request_state' => 'accepted',
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $conversationId;
        });

        $conversation = $this->conversation($conversationId, $userId);
        $this->broadcast($conversationId, 'messaging.conversation.created', ['conversation' => $conversation], $this->participantIds($conversationId));

        return $conversation;
    }

    public function conversation(int $conversationId, int $userId): array
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        return $this->conversationPayload($conversation, $userId, true);
    }

    public function updateConversation(int $conversationId, int $userId, array $data): array
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        $participant = $this->participantRow($conversationId, $userId);

        if ($conversation->type === 'group' && array_key_exists('title', $data)) {
            abort_unless(in_array($participant->role, ['owner', 'admin'], true), 403, 'Apenas administradores podem editar o grupo.');
            DB::table('conversations')->where('id', $conversationId)->update([
                'title' => trim((string) $data['title']) ?: 'Grupo',
                'updated_at' => now(),
            ]);
        }

        $participantUpdates = ['updated_at' => now()];
        if (array_key_exists('pinned', $data)) {
            $participantUpdates['pinned_at'] = $data['pinned'] ? now() : null;
        }
        if (array_key_exists('muted', $data)) {
            $participantUpdates['muted_until'] = $data['muted'] ? now()->addYears(10) : null;
        }
        if (! empty($data['notification_level'])) {
            $participantUpdates['notification_level'] = $data['notification_level'];
        }

        if (count($participantUpdates) > 1) {
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->update($participantUpdates);
        }

        $payload = $this->conversation($conversationId, $userId);
        $this->broadcast($conversationId, 'messaging.conversation.updated', ['conversation' => $payload], $this->participantIds($conversationId));

        return $payload;
    }

    public function addParticipants(int $conversationId, int $userId, array $userIds): array
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        abort_unless($conversation->type === 'group', 422, 'Participantes só podem ser adicionados a grupos.');
        $actor = $this->participantRow($conversationId, $userId);
        abort_unless(in_array($actor->role, ['owner', 'admin'], true), 403, 'Sem permissão para adicionar participantes.');

        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->take(99);
        $valid = User::query()->whereIn('id', $ids)->whereHas('applications', fn ($builder) => $builder->where('applications.id', $this->context->id()))->pluck('id');

        foreach ($valid as $participantId) {
            DB::table('conversation_participants')->updateOrInsert(
                ['conversation_id' => $conversationId, 'user_id' => (int) $participantId],
                ['role' => 'member', 'request_state' => 'accepted', 'joined_at' => now(), 'archived_at' => null, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $payload = $this->conversation($conversationId, $userId);
        $this->broadcast($conversationId, 'messaging.conversation.updated', ['conversation' => $payload], $this->participantIds($conversationId));
        return $payload;
    }

    public function removeParticipant(int $conversationId, int $userId, int $targetId): void
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        abort_unless($conversation->type === 'group', 422, 'Operação disponível apenas para grupos.');
        $actor = $this->participantRow($conversationId, $userId);
        abort_unless(in_array($actor->role, ['owner', 'admin'], true) || $targetId === $userId, 403, 'Sem permissão para remover este participante.');
        abort_if($targetId === (int) $conversation->created_by && $targetId !== $userId, 422, 'O proprietário do grupo não pode ser removido.');

        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $targetId)->delete();
        $this->broadcast($conversationId, 'messaging.conversation.updated', ['removed_user_id' => $targetId], $this->participantIds($conversationId));
    }

    public function messages(int $conversationId, int $userId, int $before = 0, string $search = ''): array
    {
        $this->ownedConversation($conversationId, $userId);
        $query = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->whereNull('deleted_at')
            ->where(fn ($builder) => $builder->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($before > 0, fn ($builder) => $builder->where('id', '<', $before))
            ->when($search !== '', fn ($builder) => $builder->where('body', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->limit(50);

        $messages = $query->get()->reverse()->values();
        $this->markRead($conversationId, $userId);

        return [
            'data' => $messages->map(fn ($message) => $this->messagePayload($message, $userId))->values()->all(),
            'meta' => [
                'has_more' => $messages->count() === 50,
                'next_before' => $messages->isNotEmpty() ? (int) $messages->first()->id : null,
            ],
        ];
    }

    public function scheduledMessages(int $conversationId, int $userId): array
    {
        $this->ownedConversation($conversationId, $userId);
        return DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_user_id', $userId)
            ->whereNull('deleted_at')
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($message) => $this->messagePayload($message, $userId))
            ->values()
            ->all();
    }

    public function send(int $conversationId, int $userId, array $payload, array $attachments = []): array
    {
        $conversation = $this->ownedConversation($conversationId, $userId);
        $body = trim((string) ($payload['body'] ?? ''));
        $replyToId = ! empty($payload['reply_to_id']) ? (int) $payload['reply_to_id'] : null;
        $type = trim((string) ($payload['type'] ?? 'text')) ?: 'text';
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $clientUuid = trim((string) ($payload['client_uuid'] ?? '')) ?: null;
        $scheduledAt = $this->parseFutureDate($payload['scheduled_at'] ?? null);
        $expiresAt = $this->parseFutureDate($payload['expires_at'] ?? null);

        abort_if($body === '' && empty($attachments) && empty($metadata), 422, 'A mensagem precisa ter texto, mídia ou conteúdo compartilhado.');
        abort_if(! in_array($type, ['text', 'image', 'video', 'audio', 'file', 'share', 'location', 'system'], true), 422, 'Tipo de mensagem inválido.');

        if ($conversation->type === 'direct') {
            $otherParticipant = DB::table('conversation_participants')
                ->where('conversation_id', $conversationId)
                ->where('user_id', '<>', $userId)
                ->first();
            $otherId = (int) ($otherParticipant?->user_id ?: 0);
            abort_if($otherId > 0 && $this->blockedEitherWay($userId, $otherId), 403, 'Não é possível enviar mensagens para este usuário.');
            abort_if(($otherParticipant?->request_state ?? 'accepted') === 'rejected', 403, 'Esta solicitação de mensagem não foi aceita.');
        }

        if ($clientUuid) {
            $existing = DB::table('messages')->where('conversation_id', $conversationId)->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $this->messagePayload($existing, $userId);
            }
        }

        $replyTo = $replyToId
            ? DB::table('messages')->where('id', $replyToId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->first()
            : null;
        abort_if($replyToId && ! $replyTo, 422, 'Mensagem respondida não encontrada.');

        $storedFiles = [];
        $messageId = null;

        try {
            foreach ($attachments as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }
                $storedFiles[] = $this->files->store($file, 'messaging/'.$this->context->id().'/'.$conversationId, 'local');
            }

            $messageId = DB::transaction(function () use ($conversationId, $userId, $body, $replyTo, $type, $metadata, $clientUuid, $scheduledAt, $expiresAt, $storedFiles) {
                $now = now();
                $messageId = DB::table('messages')->insertGetId([
                    'conversation_id' => $conversationId,
                    'sender_user_id' => $userId,
                    'reply_to_id' => $replyTo?->id,
                    'client_uuid' => $clientUuid ?: (string) Str::uuid(),
                    'type' => $type,
                    'body' => $body !== '' ? $body : null,
                    'metadata' => ! empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'scheduled_at' => $scheduledAt,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($storedFiles as $stored) {
                    DB::table('message_attachments')->insert([
                        'message_id' => $messageId,
                        'uuid' => $stored['uuid'],
                        'kind' => $this->attachmentKind((string) $stored['mime_type']),
                        'original_name' => $stored['original_name'],
                        'mime_type' => $stored['mime_type'],
                        'storage_disk' => $stored['storage_disk'],
                        'storage_path' => $stored['storage_path'],
                        'file_size' => $stored['file_size'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('conversation_participants')
                    ->where('conversation_id', $conversationId)
                    ->where('user_id', $userId)
                    ->update(['last_read_at' => $now, 'archived_at' => null, 'updated_at' => $now]);

                if (! $scheduledAt || $scheduledAt->lte($now)) {
                    DB::table('conversations')->where('id', $conversationId)->update(['last_message_at' => $now, 'updated_at' => $now]);
                }

                return $messageId;
            });
        } catch (\Throwable $exception) {
            foreach ($storedFiles as $stored) {
                $this->files->delete($stored['storage_disk'], $stored['storage_path']);
            }
            throw $exception;
        }

        $message = $this->messagePayload(DB::table('messages')->where('id', $messageId)->first(), $userId);
        if (! $scheduledAt || $scheduledAt->lte(now())) {
            DB::table('messages')->where('id', $messageId)->update(['delivered_at' => now()]);
            $this->deliverMessage($conversationId, $userId, $message);
        }

        return $message;
    }

    public function editMessage(int $conversationId, int $messageId, int $userId, string $body): array
    {
        $this->ownedConversation($conversationId, $userId);
        $message = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->first();
        abort_unless($message && (int) $message->sender_user_id === $userId, 403, 'Você só pode editar suas próprias mensagens.');
        abort_if(Carbon::parse($message->created_at)->lt(now()->subMinutes(15)), 422, 'O prazo para editar esta mensagem expirou.');

        DB::table('messages')->where('id', $messageId)->update(['body' => trim($body), 'edited_at' => now(), 'updated_at' => now()]);
        $payload = $this->messagePayload(DB::table('messages')->where('id', $messageId)->first(), $userId);
        $this->broadcast($conversationId, 'messaging.message.updated', ['message' => $payload], $this->participantIds($conversationId));
        return $payload;
    }

    public function deleteMessage(int $conversationId, int $messageId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $message = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->first();
        abort_unless($message && (int) $message->sender_user_id === $userId, 403, 'Você só pode cancelar o envio das suas mensagens.');

        DB::table('messages')->where('id', $messageId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->engagement->cancelMessage($messageId);
        $this->broadcast($conversationId, 'messaging.message.deleted', ['message_id' => $messageId], $this->participantIds($conversationId));
    }

    public function cancelScheduledMessage(int $conversationId, int $messageId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $message = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->where('sender_user_id', $userId)->where('scheduled_at', '>', now())->whereNull('deleted_at')->first();
        abort_unless($message, 404, 'Mensagem agendada não encontrada.');
        DB::table('messages')->where('id', $messageId)->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function react(int $conversationId, int $messageId, int $userId, string $emoji): array
    {
        $this->ownedConversation($conversationId, $userId);
        abort_unless(DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->exists(), 404, 'Mensagem não encontrada.');

        DB::table('message_reactions')->updateOrInsert(
            ['message_id' => $messageId, 'user_id' => $userId, 'emoji' => $emoji],
            ['updated_at' => now(), 'created_at' => now()]
        );

        $reactions = $this->reactions($messageId);
        $this->broadcast($conversationId, 'messaging.reaction.updated', ['message_id' => $messageId, 'reactions' => $reactions], $this->participantIds($conversationId));
        return $reactions;
    }

    public function removeReaction(int $conversationId, int $messageId, int $userId, string $emoji): array
    {
        $this->ownedConversation($conversationId, $userId);
        DB::table('message_reactions')->where('message_id', $messageId)->where('user_id', $userId)->where('emoji', $emoji)->delete();
        $reactions = $this->reactions($messageId);
        $this->broadcast($conversationId, 'messaging.reaction.updated', ['message_id' => $messageId, 'reactions' => $reactions], $this->participantIds($conversationId));
        return $reactions;
    }

    public function pinMessage(int $conversationId, int $messageId, int $userId, bool $pin): array
    {
        $this->ownedConversation($conversationId, $userId);
        abort_unless(DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->whereNull('deleted_at')->exists(), 404, 'Mensagem não encontrada.');

        if ($pin) {
            DB::table('message_pins')->updateOrInsert(
                ['conversation_id' => $conversationId, 'message_id' => $messageId],
                ['pinned_by' => $userId, 'updated_at' => now(), 'created_at' => now()]
            );
        } else {
            DB::table('message_pins')->where('conversation_id', $conversationId)->where('message_id', $messageId)->delete();
        }

        $pins = $this->pinnedMessages($conversationId, $userId);
        $this->broadcast($conversationId, 'messaging.pins.updated', ['pinned_messages' => $pins], $this->participantIds($conversationId));
        return $pins;
    }

    public function pinnedMessages(int $conversationId, int $userId): array
    {
        $this->ownedConversation($conversationId, $userId);
        return DB::table('message_pins as mp')
            ->join('messages as m', 'm.id', '=', 'mp.message_id')
            ->where('mp.conversation_id', $conversationId)
            ->whereNull('m.deleted_at')
            ->orderByDesc('mp.created_at')
            ->limit(10)
            ->get('m.*')
            ->map(fn ($message) => $this->messagePayload($message, $userId))
            ->values()
            ->all();
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        $now = now();
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->update(['last_read_at' => $now, 'last_delivered_at' => $now, 'updated_at' => $now]);
        $this->engagement->markRead($conversationId, $userId);

        $messageIds = DB::table('messages')->where('conversation_id', $conversationId)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')->pluck('id');
        foreach ($messageIds as $messageId) {
            DB::table('message_receipts')->updateOrInsert(
                ['message_id' => (int) $messageId, 'user_id' => $userId],
                ['delivered_at' => $now, 'read_at' => $now, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $this->broadcast($conversationId, 'messaging.message.read', ['user_id' => $userId, 'read_at' => $now->toISOString()], $this->participantIds($conversationId));
    }

    public function archive(int $conversationId, int $userId): void
    {
        $this->ownedConversation($conversationId, $userId);
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->update(['archived_at' => now(), 'updated_at' => now()]);
    }

    public function typing(int $conversationId, int $userId, bool $typing): void
    {
        $this->ownedConversation($conversationId, $userId);
        $key = $this->typingKey($conversationId, $userId);
        if ($typing) {
            Cache::put($key, true, now()->addSeconds(8));
        } else {
            Cache::forget($key);
        }
        $this->broadcast($conversationId, 'messaging.typing', ['user_id' => $userId, 'typing' => $typing], $this->participantIds($conversationId));
    }

    public function heartbeat(int $userId): array
    {
        Cache::put($this->presenceKey($userId), now()->timestamp, now()->addSeconds(90));
        return ['online' => true, 'last_seen_at' => now()->toISOString()];
    }

    public function presence(int $userId, int $targetId): array
    {
        $settings = $this->settings($targetId);
        if (! ($settings['show_activity_status'] ?? true)) {
            return ['online' => null, 'last_seen_at' => null];
        }
        $timestamp = (int) (Cache::get($this->presenceKey($targetId)) ?: 0);
        return [
            'online' => $timestamp > now()->subSeconds(90)->timestamp,
            'last_seen_at' => $timestamp ? Carbon::createFromTimestamp($timestamp)->toISOString() : null,
        ];
    }

    public function settings(int $userId): array
    {
        $row = DB::table('messaging_user_settings')->where('app_id', $this->context->id())->where('user_id', $userId)->first();
        return [
            'allow_messages_from' => $row->allow_messages_from ?? 'everyone',
            'show_activity_status' => isset($row->show_activity_status) ? (bool) $row->show_activity_status : true,
            'send_read_receipts' => isset($row->send_read_receipts) ? (bool) $row->send_read_receipts : true,
            'allow_group_invites' => isset($row->allow_group_invites) ? (bool) $row->allow_group_invites : true,
            'muted_words' => $row?->muted_words ? json_decode($row->muted_words, true) : [],
            'email_new_messages' => isset($row->email_new_messages) ? (bool) $row->email_new_messages : true,
            'push_new_messages' => isset($row->push_new_messages) ? (bool) $row->push_new_messages : true,
            'unread_reminders' => isset($row->unread_reminders) ? (bool) $row->unread_reminders : true,
            'digest_messages' => isset($row->digest_messages) ? (bool) $row->digest_messages : true,
            'include_message_preview' => isset($row->include_message_preview) ? (bool) $row->include_message_preview : true,
            'email_cooldown_minutes' => max(1, min((int) ($row->email_cooldown_minutes ?? 10), 120)),
            'first_reminder_minutes' => max(15, min((int) ($row->first_reminder_minutes ?? 120), 1440)),
            'second_reminder_minutes' => max(60, min((int) ($row->second_reminder_minutes ?? 720), 4320)),
        ];
    }

    public function updateSettings(int $userId, array $data): array
    {
        $payload = [
            'allow_messages_from' => $data['allow_messages_from'] ?? 'everyone',
            'show_activity_status' => (bool) ($data['show_activity_status'] ?? true),
            'send_read_receipts' => (bool) ($data['send_read_receipts'] ?? true),
            'allow_group_invites' => (bool) ($data['allow_group_invites'] ?? true),
            'muted_words' => json_encode(array_values($data['muted_words'] ?? []), JSON_UNESCAPED_UNICODE),
            'email_new_messages' => (bool) ($data['email_new_messages'] ?? true),
            'push_new_messages' => (bool) ($data['push_new_messages'] ?? true),
            'unread_reminders' => (bool) ($data['unread_reminders'] ?? true),
            'digest_messages' => (bool) ($data['digest_messages'] ?? true),
            'include_message_preview' => (bool) ($data['include_message_preview'] ?? true),
            'email_cooldown_minutes' => max(1, min((int) ($data['email_cooldown_minutes'] ?? 10), 120)),
            'first_reminder_minutes' => max(15, min((int) ($data['first_reminder_minutes'] ?? 120), 1440)),
            'second_reminder_minutes' => max(60, min((int) ($data['second_reminder_minutes'] ?? 720), 4320)),
            'updated_at' => now(),
        ];
        DB::table('messaging_user_settings')->updateOrInsert(
            ['app_id' => $this->context->id(), 'user_id' => $userId],
            $payload + ['created_at' => now()]
        );
        return $this->settings($userId);
    }

    public function block(int $userId, int $targetId, string $kind = 'block'): void
    {
        abort_if($userId === $targetId, 422, 'Você não pode bloquear a si mesmo.');
        DB::table('messaging_blocks')->updateOrInsert(
            ['app_id' => $this->context->id(), 'blocker_user_id' => $userId, 'blocked_user_id' => $targetId],
            ['kind' => $kind, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function unblock(int $userId, int $targetId): void
    {
        DB::table('messaging_blocks')->where('app_id', $this->context->id())->where('blocker_user_id', $userId)->where('blocked_user_id', $targetId)->delete();
    }

    public function report(int $conversationId, int $userId, ?int $messageId, string $reason, ?string $details): array
    {
        $this->ownedConversation($conversationId, $userId);
        if ($messageId) {
            abort_unless(DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->exists(), 404, 'Mensagem não encontrada.');
        }
        $id = DB::table('message_reports')->insertGetId([
            'app_id' => $this->context->id(),
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'reporter_user_id' => $userId,
            'reason' => $reason,
            'details' => $details,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['id' => $id, 'status' => 'open'];
    }

    public function attachment(int $attachmentId, int $userId): object
    {
        $attachment = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'm.conversation_id')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('a.id', $attachmentId)
            ->where('cp.user_id', $userId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('m.deleted_at')
            ->select('a.*')
            ->first();
        abort_unless($attachment, 404, 'Anexo não encontrado.');
        abort_unless($this->files->exists($attachment->storage_disk, $attachment->storage_path), 404, 'Arquivo não encontrado.');
        return $attachment;
    }

    public function startCall(int $conversationId, int $userId, string $type, array $metadata = []): array
    {
        $this->ownedConversation($conversationId, $userId);
        $id = DB::table('messaging_calls')->insertGetId([
            'conversation_id' => $conversationId,
            'started_by' => $userId,
            'type' => $type,
            'status' => 'ringing',
            'started_at' => now(),
            'metadata' => ! empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $call = (array) DB::table('messaging_calls')->where('id', $id)->first();
        $this->broadcast($conversationId, 'messaging.call.updated', ['call' => $call], $this->participantIds($conversationId));
        return $call;
    }

    public function updateCall(int $conversationId, int $callId, int $userId, string $status, array $metadata = []): array
    {
        $this->ownedConversation($conversationId, $userId);
        $call = DB::table('messaging_calls')->where('id', $callId)->where('conversation_id', $conversationId)->first();
        abort_unless($call, 404, 'Chamada não encontrada.');
        $updates = ['status' => $status, 'metadata' => ! empty($metadata) ? json_encode($metadata) : $call->metadata, 'updated_at' => now()];
        if ($status === 'active' && ! $call->answered_at) $updates['answered_at'] = now();
        if (in_array($status, ['ended', 'declined', 'missed'], true)) $updates['ended_at'] = now();
        DB::table('messaging_calls')->where('id', $callId)->update($updates);
        $payload = (array) DB::table('messaging_calls')->where('id', $callId)->first();
        $this->broadcast($conversationId, 'messaging.call.updated', ['call' => $payload], $this->participantIds($conversationId));
        return $payload;
    }

    public function dispatchDueScheduled(int $limit = 100): int
    {
        $messages = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.app_id', $this->context->id())
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.scheduled_at')
            ->where('m.scheduled_at', '<=', now())
            ->whereNull('m.delivered_at')
            ->orderBy('m.scheduled_at')
            ->limit(max(1, min($limit, 500)))
            ->get(['m.*']);

        foreach ($messages as $message) {
            DB::transaction(function () use ($message) {
                $now = now();
                DB::table('messages')->where('id', $message->id)->whereNull('delivered_at')->update(['delivered_at' => $now, 'updated_at' => $now]);
                DB::table('conversations')->where('id', $message->conversation_id)->update(['last_message_at' => $now, 'updated_at' => $now]);
            });

            $fresh = DB::table('messages')->where('id', $message->id)->first();
            $payload = $this->messagePayload($fresh, (int) $message->sender_user_id);
            $this->deliverMessage((int) $message->conversation_id, (int) $message->sender_user_id, $payload);
        }

        return $messages->count();
    }

    private function deliverMessage(int $conversationId, int $senderId, array $message): void
    {
        $recipientIds = $this->participantIds($conversationId)->filter(fn ($id) => $id !== $senderId)->values();
        $now = now();

        $this->engagement->markResponse($conversationId, $senderId);

        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', '<>', $senderId)
            ->update(['archived_at' => null, 'updated_at' => $now]);

        foreach ($recipientIds as $recipientId) {
            DB::table('message_receipts')->updateOrInsert(
                ['message_id' => (int) $message['id'], 'user_id' => $recipientId],
                ['delivered_at' => $now, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $this->broadcast($conversationId, 'messaging.message.created', ['message' => $message], $this->participantIds($conversationId));

        foreach ($recipientIds as $recipientId) {
            $this->engagement->queueMessage((int) $message['id'], (int) $recipientId);
            $participant = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $recipientId)->first();
            if ($participant?->muted_until && Carbon::parse($participant->muted_until)->isFuture()) {
                continue;
            }
            $sender = User::find($senderId);
            $preview = $message['body'] ?: $this->messageTypeLabel($message['type'] ?? 'text');
            $this->notifications->sendToUser($this->context->id(), $recipientId, [
                'type' => 'direct_message',
                'title' => $sender ? $this->displayName($sender) : 'Nova mensagem',
                'message' => Str::limit($preview, 140),
                'reference_type' => 'conversation',
                'reference_id' => $conversationId,
                'reference_url' => '/messages?conversation='.$conversationId,
                'data' => ['conversation_id' => $conversationId, 'message_id' => $message['id']],
                'send_email' => false,
            ]);
        }
    }

    private function conversationPayload(object $conversation, int $userId, bool $includeDetails = false): array
    {
        $participant = DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $userId)->first();
        $other = $conversation->type === 'direct' ? $this->otherParticipant((int) $conversation->id, $userId) : null;
        $lastMessage = DB::table('messages')->where('conversation_id', $conversation->id)->whereNull('deleted_at')
            ->where(fn ($builder) => $builder->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')->first();

        $unread = DB::table('messages')->where('conversation_id', $conversation->id)->where('sender_user_id', '<>', $userId)->whereNull('deleted_at')
            ->where(fn ($builder) => $builder->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($participant?->last_read_at, fn ($query) => $query->where('created_at', '>', $participant->last_read_at))
            ->when(! $participant?->last_read_at, fn ($query) => $query->where('created_at', '>=', $conversation->created_at))
            ->count();

        $payload = [
            'id' => (int) $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->type === 'group' ? ($conversation->title ?: 'Grupo') : null,
            'avatar' => $conversation->avatar_path ?? null,
            'user' => $other ? $this->userPayload($other) : null,
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage, $userId) : null,
            'unread_count' => $unread,
            'muted_until' => $participant?->muted_until,
            'pinned' => (bool) $participant?->pinned_at,
            'notification_level' => $participant?->notification_level ?? 'all',
            'request_state' => $participant?->request_state ?? 'accepted',
            'updated_at' => $conversation->last_message_at ?: $conversation->updated_at,
        ];

        if ($includeDetails) {
            $payload['participants'] = DB::table('conversation_participants as cp')
                ->join('users as u', 'u.id', '=', 'cp.user_id')
                ->where('cp.conversation_id', $conversation->id)
                ->orderByRaw("CASE WHEN cp.role = 'owner' THEN 0 WHEN cp.role = 'admin' THEN 1 ELSE 2 END")
                ->get(['u.id', 'u.user_name', 'u.first_name', 'u.last_name', 'u.avatar', 'cp.role', 'cp.nickname'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'user_name' => $row->user_name,
                    'name' => trim($row->first_name.' '.$row->last_name) ?: ($row->user_name ?: 'Usuário'),
                    'avatar' => $row->avatar,
                    'role' => $row->role,
                    'nickname' => $row->nickname,
                ])->values()->all();
            $payload['pinned_messages'] = $this->pinnedMessages((int) $conversation->id, $userId);
        }

        return $payload;
    }

    private function messagePayload(object $message, int $viewerId = 0): array
    {
        $metadata = is_array($message->metadata ?? null) ? $message->metadata : json_decode((string) ($message->metadata ?? ''), true);
        $attachments = DB::table('message_attachments')->where('message_id', $message->id)->orderBy('id')->get()->map(fn ($attachment) => [
            'id' => (int) $attachment->id,
            'uuid' => $attachment->uuid,
            'kind' => $attachment->kind,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->file_size,
            'duration_ms' => $attachment->duration_ms ? (int) $attachment->duration_ms : null,
            'width' => $attachment->width ? (int) $attachment->width : null,
            'height' => $attachment->height ? (int) $attachment->height : null,
            'url' => '/messaging/attachments/'.(int) $attachment->id,
        ])->values()->all();

        $reply = null;
        if ($message->reply_to_id) {
            $replyRow = DB::table('messages')->where('id', $message->reply_to_id)->first();
            if ($replyRow) {
                $reply = [
                    'id' => (int) $replyRow->id,
                    'sender_user_id' => (int) $replyRow->sender_user_id,
                    'type' => $replyRow->type,
                    'body' => $replyRow->body,
                ];
            }
        }

        $receipts = $viewerId > 0 && (int) $message->sender_user_id === $viewerId
            ? DB::table('message_receipts')->where('message_id', $message->id)->get(['user_id', 'delivered_at', 'read_at'])->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'delivered_at' => $row->delivered_at,
                'read_at' => $row->read_at,
            ])->values()->all()
            : [];

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_user_id' => (int) $message->sender_user_id,
            'reply_to_id' => $message->reply_to_id ? (int) $message->reply_to_id : null,
            'reply_to' => $reply,
            'client_uuid' => $message->client_uuid ?? null,
            'type' => $message->type,
            'body' => $message->body,
            'metadata' => $metadata ?: new \stdClass(),
            'attachments' => $attachments,
            'reactions' => $this->reactions((int) $message->id),
            'receipts' => $receipts,
            'edited_at' => $message->edited_at,
            'scheduled_at' => $message->scheduled_at ?? null,
            'expires_at' => $message->expires_at ?? null,
            'created_at' => $message->created_at,
        ];
    }

    private function reactions(int $messageId): array
    {
        return DB::table('message_reactions')
            ->where('message_id', $messageId)
            ->orderBy('id')
            ->get(['user_id', 'emoji'])
            ->groupBy('emoji')
            ->map(fn ($rows, $emoji) => [
                'emoji' => $emoji,
                'count' => $rows->count(),
                'user_ids' => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all();
    }

    private function ownedConversation(int $conversationId, int $userId): object
    {
        $conversation = DB::table('conversations as c')
            ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'c.id')
            ->where('c.id', $conversationId)
            ->where('c.app_id', $this->context->id())
            ->where('cp.user_id', $userId)
            ->whereNull('c.deleted_at')
            ->select('c.*')
            ->first();
        abort_unless($conversation, 404, 'Conversa não encontrada.');
        return $conversation;
    }

    private function participantRow(int $conversationId, int $userId): object
    {
        $participant = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->first();
        abort_unless($participant, 404, 'Participante não encontrado.');
        return $participant;
    }

    private function otherParticipant(int $conversationId, int $userId): ?User
    {
        $otherId = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', '<>', $userId)->value('user_id');
        return $otherId ? User::find($otherId) : null;
    }

    private function participantIds(int $conversationId)
    {
        return DB::table('conversation_participants')->where('conversation_id', $conversationId)->pluck('user_id')->map(fn ($id) => (int) $id);
    }

    private function userPayload(User $user, bool $includeEmail = false): array
    {
        $payload = [
            'id' => (int) $user->id,
            'user_name' => $user->user_name,
            'name' => $this->displayName($user),
            'avatar' => $user->avatar,
        ];
        if ($includeEmail) {
            $payload['email'] = $user->email;
        }
        return $payload;
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->first_name.' '.(string) $user->last_name);
        return $name !== '' ? $name : ((string) $user->user_name ?: 'Usuário');
    }

    private function attachmentKind(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        return 'file';
    }

    private function parseFutureDate(mixed $value): ?Carbon
    {
        if (! $value) return null;
        $date = Carbon::parse((string) $value);
        return $date->isFuture() ? $date : null;
    }

    private function blockedEitherWay(int $one, int $two): bool
    {
        return DB::table('messaging_blocks')->where('app_id', $this->context->id())
            ->where(function ($query) use ($one, $two) {
                $query->where(fn ($nested) => $nested->where('blocker_user_id', $one)->where('blocked_user_id', $two))
                    ->orWhere(fn ($nested) => $nested->where('blocker_user_id', $two)->where('blocked_user_id', $one));
            })
            ->where('kind', 'block')
            ->exists();
    }

    private function typingKey(int $conversationId, int $userId): string
    {
        return 'messaging:typing:'.$this->context->id().':'.$conversationId.':'.$userId;
    }

    private function presenceKey(int $userId): string
    {
        return 'messaging:presence:'.$this->context->id().':'.$userId;
    }

    private function broadcast(int $conversationId, string $eventName, array $payload, $userIds = []): void
    {
        event(new MessagingRealtimeEvent($conversationId, $eventName, $payload, collect($userIds)->map(fn ($id) => (int) $id)->all()));
    }

    private function messageTypeLabel(string $type): string
    {
        return match ($type) {
            'image' => 'Enviou uma foto',
            'video' => 'Enviou um vídeo',
            'audio' => 'Enviou um áudio',
            'file' => 'Enviou um arquivo',
            'share' => 'Compartilhou um conteúdo',
            'location' => 'Compartilhou uma localização',
            default => 'Nova mensagem',
        };
    }
}
