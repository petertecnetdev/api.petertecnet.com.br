<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Support\AppMessagePayload;
use App\Events\AppConversationRead;
use App\Events\AppConversationTyping;
use App\Events\AppMessageChanged;
use App\Events\AppMessageCreated;
use App\Models\AppConversation;
use App\Models\AppMessage;
use App\Models\AppMessageAttachment;
use App\Models\AppMessageReaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AppMessagingService
{
    public const REACTIONS = ['❤️', '😂', '😮', '😢', '😡', '👍', '🔥', '👏'];

    public const REPORT_REASONS = [
        'spam',
        'harassment',
        'hate',
        'sexual_content',
        'violence',
        'scam',
        'impersonation',
        'other',
    ];

    public function conversations(int $userId, int $applicationId, int $perPage = 25, bool $archived = false): array
    {
        $perPage = min(max($perPage, 1), 50);

        $query = AppConversation::query()
            ->select('app_conversations.*')
            ->join('app_conversation_participants as current_participant', function ($join) use ($userId) {
                $join->on('current_participant.conversation_id', '=', 'app_conversations.id')
                    ->where('current_participant.user_id', '=', $userId);
            })
            ->where('app_conversations.application_id', $applicationId)
            ->with([
                'users:id,first_name,last_name,user_name,email,avatar,city,uf,about',
                'lastMessage.sender:id,first_name,last_name,user_name,avatar',
                'lastMessage.replyTo.sender:id,first_name,last_name,user_name,avatar',
                'lastMessage.attachments',
                'lastMessage.reactions.user:id,first_name,last_name,user_name,avatar',
            ]);

        $archived
            ? $query->whereNotNull('current_participant.archived_at')
            : $query->whereNull('current_participant.archived_at');

        $conversations = $query
            ->orderByRaw('current_participant.pinned_at IS NULL')
            ->orderByDesc('current_participant.pinned_at')
            ->orderByRaw('app_conversations.last_message_at IS NULL')
            ->orderByDesc('app_conversations.last_message_at')
            ->orderByDesc('app_conversations.id')
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
        $blockedIds = $this->blockedUserIds($userId, $applicationId);

        $query = User::query()
            ->where('users.id', '<>', $userId)
            ->when($blockedIds !== [], fn ($builder) => $builder->whereNotIn('users.id', $blockedIds))
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
            ->map(fn (User $person) => AppMessagePayload::person($person));
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

        if ($this->isBlockedBetween($userId, $recipientId, $applicationId)) {
            throw ValidationException::withMessages([
                'recipient_user_id' => ['Esta conversa não pode ser iniciada enquanto houver um bloqueio entre os usuários.'],
            ]);
        }

        $ids = [$userId, $recipientId];
        sort($ids, SORT_NUMERIC);
        $directKey = implode(':', $ids);

        $conversation = DB::transaction(function () use ($applicationId, $directKey, $ids, $userId) {
            $conversation = AppConversation::query()->firstOrCreate(
                ['application_id' => $applicationId, 'direct_key' => $directKey],
                ['type' => 'direct']
            );

            foreach ($ids as $participantId) {
                DB::table('app_conversation_participants')->insertOrIgnore([
                    'conversation_id' => $conversation->id,
                    'user_id' => $participantId,
                    'unread_count' => 0,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('app_conversation_participants')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->update(['archived_at' => null, 'updated_at' => now()]);

            return $conversation;
        });

        return $this->conversationPayload($this->loadConversationRelations($conversation), $userId);
    }

    public function messages(int $userId, int $applicationId, int $conversationId, int $perPage = 40): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $perPage = min(max($perPage, 1), 100);

        $messages = AppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with($this->messageRelations())
            ->orderByDesc('id')
            ->paginate($perPage);

        $messages->setCollection(
            $messages->getCollection()
                ->reverse()
                ->values()
                ->map(fn (AppMessage $message) => AppMessagePayload::make($message))
        );

        return [
            'conversation' => $this->conversationPayload($this->loadConversationRelations($conversation), $userId),
            'messages' => $messages,
        ];
    }

    public function searchMessages(int $userId, int $applicationId, int $conversationId, string $term): Collection
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like = '%'.$escaped.'%';

        return AppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($like) {
                $query->where('body', 'like', $like)
                    ->orWhereHas('attachments', fn ($attachmentQuery) => $attachmentQuery->where('original_name', 'like', $like));
            })
            ->with($this->messageRelations())
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AppMessage $message) => AppMessagePayload::make($message));
    }

    public function send(
        int $userId,
        int $applicationId,
        int $conversationId,
        ?string $body,
        array $attachments = [],
        ?int $replyToMessageId = null,
        ?string $clientToken = null,
    ): array {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $this->assertConversationWritable($conversation, $userId, $applicationId);

        $body = trim((string) $body);
        $attachments = array_values(array_filter($attachments, fn ($file) => $file instanceof UploadedFile));
        $clientToken = $clientToken ? trim($clientToken) : null;

        if ($body === '' && $attachments === []) {
            throw ValidationException::withMessages(['body' => ['Digite uma mensagem ou adicione um arquivo antes de enviar.']]);
        }

        if ($clientToken) {
            $existing = AppMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_user_id', $userId)
                ->where('client_token', $clientToken)
                ->with($this->messageRelations())
                ->first();

            if ($existing) {
                return AppMessagePayload::make($existing);
            }
        }

        $replyTo = null;
        if ($replyToMessageId) {
            $replyTo = AppMessage::query()
                ->whereKey($replyToMessageId)
                ->where('conversation_id', $conversation->id)
                ->whereNull('deleted_at')
                ->first();

            if (! $replyTo) {
                throw ValidationException::withMessages([
                    'reply_to_message_id' => ['A mensagem respondida não está mais disponível nesta conversa.'],
                ]);
            }
        }

        $storedFiles = [];

        try {
            $message = DB::transaction(function () use (
                $conversation,
                $applicationId,
                $userId,
                $body,
                $attachments,
                $replyTo,
                $clientToken,
                &$storedFiles,
            ) {
                $message = AppMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_user_id' => $userId,
                    'reply_to_message_id' => $replyTo?->id,
                    'type' => $attachments === [] ? 'text' : ($body === '' ? 'media' : 'mixed'),
                    'client_token' => $clientToken,
                    'body' => $body,
                ]);

                foreach ($attachments as $file) {
                    $attachment = $this->storeAttachment($file, $applicationId, (int) $conversation->id, (int) $message->id);
                    $storedFiles[] = [$attachment->storage_disk, $attachment->storage_path];
                }

                $conversation->forceFill(['last_message_at' => $message->created_at])->save();

                DB::table('app_conversation_participants')
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $userId)
                    ->update([
                        'unread_count' => 0,
                        'last_read_at' => now(),
                        'archived_at' => null,
                        'updated_at' => now(),
                    ]);

                DB::table('app_conversation_participants')
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', '<>', $userId)
                    ->update([
                        'unread_count' => DB::raw('unread_count + 1'),
                        'archived_at' => null,
                        'updated_at' => now(),
                    ]);

                return $message;
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        $message->load($this->messageRelations());
        broadcast(new AppMessageCreated(
            $message,
            $this->participantIds($conversation),
            (int) $conversation->application_id,
        ));

        return AppMessagePayload::make($message);
    }

    public function updateMessage(int $userId, int $applicationId, int $conversationId, int $messageId, string $body): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $this->assertConversationWritable($conversation, $userId, $applicationId);
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['A mensagem editada não pode ficar vazia.']]);
        }

        $message = AppMessage::query()
            ->whereKey($messageId)
            ->where('conversation_id', $conversation->id)
            ->where('sender_user_id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
        $message->load($this->messageRelations());

        broadcast(new AppMessageChanged(
            $message,
            'updated',
            $this->participantIds($conversation),
            $applicationId,
        ));

        return AppMessagePayload::make($message);
    }

    public function deleteMessage(int $userId, int $applicationId, int $conversationId, int $messageId): array
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $message = AppMessage::query()
            ->whereKey($messageId)
            ->where('conversation_id', $conversation->id)
            ->where('sender_user_id', $userId)
            ->with(['attachments', 'reactions'])
            ->firstOrFail();

        if ($message->deleted_at === null) {
            foreach ($message->attachments as $attachment) {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
            }

            DB::transaction(function () use ($message, $conversation) {
                $message->attachments()->delete();
                $message->reactions()->delete();
                $message->forceFill([
                    'body' => '',
                    'metadata' => null,
                    'deleted_at' => now(),
                ])->save();

                $lastMessageAt = AppMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereNull('deleted_at')
                    ->max('created_at');

                $conversation->forceFill(['last_message_at' => $lastMessageAt])->save();
            });
        }

        $message->load($this->messageRelations());
        broadcast(new AppMessageChanged(
            $message,
            'deleted',
            $this->participantIds($conversation),
            $applicationId,
        ));

        return AppMessagePayload::make($message);
    }

    public function toggleReaction(
        int $userId,
        int $applicationId,
        int $conversationId,
        int $messageId,
        string $emoji,
    ): array {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $message = AppMessage::query()
            ->whereKey($messageId)
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        if (! in_array($emoji, self::REACTIONS, true)) {
            throw ValidationException::withMessages(['emoji' => ['Esta reação não é suportada.']]);
        }

        DB::transaction(function () use ($message, $userId, $emoji) {
            $existing = AppMessageReaction::query()
                ->where('message_id', $message->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing && $existing->emoji === $emoji) {
                $existing->delete();
                return;
            }

            AppMessageReaction::query()->updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $userId],
                ['emoji' => $emoji],
            );
        });

        $message->load($this->messageRelations());
        broadcast(new AppMessageChanged(
            $message,
            'reaction',
            $this->participantIds($conversation),
            $applicationId,
        ));

        return AppMessagePayload::make($message);
    }

    public function typing(int $userId, int $applicationId, int $conversationId, bool $isTyping): void
    {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $this->assertConversationWritable($conversation, $userId, $applicationId);

        broadcast(new AppConversationTyping(
            (int) $conversation->id,
            $userId,
            $isTyping,
            $this->participantIds($conversation),
            $applicationId,
        ));
    }

    public function updatePreferences(
        int $userId,
        int $applicationId,
        int $conversationId,
        array $preferences,
    ): array {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);
        $updates = ['updated_at' => now()];

        if (array_key_exists('archived', $preferences)) {
            $updates['archived_at'] = $preferences['archived'] ? now() : null;
        }
        if (array_key_exists('pinned', $preferences)) {
            $updates['pinned_at'] = $preferences['pinned'] ? now() : null;
        }
        if (array_key_exists('muted_until', $preferences)) {
            $updates['muted_until'] = $preferences['muted_until']
                ? Carbon::parse($preferences['muted_until'])
                : null;
        }

        DB::table('app_conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->update($updates);

        return $this->conversationPayload(
            $this->loadConversationRelations($conversation->fresh()),
            $userId,
        );
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

        broadcast(new AppConversationRead(
            (int) $conversation->id,
            $userId,
            $this->participantIds($conversation),
            (int) $conversation->application_id,
            $readAt->toISOString(),
        ));

        return [
            'read' => true,
            'read_at' => $readAt->toISOString(),
            'unread_count' => $this->totalUnread($userId, (int) $conversation->application_id),
        ];
    }

    public function blockUser(int $userId, int $targetUserId, int $applicationId): void
    {
        $this->assertTargetUserAvailable($userId, $targetUserId, $applicationId);

        DB::table('app_messaging_blocks')->insertOrIgnore([
            'application_id' => $applicationId,
            'blocker_user_id' => $userId,
            'blocked_user_id' => $targetUserId,
            'created_at' => now(),
        ]);
    }

    public function unblockUser(int $userId, int $targetUserId, int $applicationId): void
    {
        DB::table('app_messaging_blocks')
            ->where('application_id', $applicationId)
            ->where('blocker_user_id', $userId)
            ->where('blocked_user_id', $targetUserId)
            ->delete();
    }

    public function reportUser(
        int $userId,
        int $targetUserId,
        int $applicationId,
        string $reason,
        ?string $description = null,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): array {
        $this->assertTargetUserAvailable($userId, $targetUserId, $applicationId);

        if (! in_array($reason, self::REPORT_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => ['Motivo de denúncia inválido.']]);
        }

        if ($conversationId) {
            $this->authorizedConversation($applicationId, $conversationId, $userId);
        }

        if ($messageId && $conversationId) {
            $validMessage = AppMessage::query()
                ->whereKey($messageId)
                ->where('conversation_id', $conversationId)
                ->exists();
            if (! $validMessage) {
                throw ValidationException::withMessages(['message_id' => ['Mensagem inválida para esta denúncia.']]);
            }
        }

        $id = DB::table('app_messaging_reports')->insertGetId([
            'application_id' => $applicationId,
            'reporter_user_id' => $userId,
            'reported_user_id' => $targetUserId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'reason' => $reason,
            'description' => $description ? trim($description) : null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => (int) $id, 'status' => 'open'];
    }

    public function downloadAttachment(
        int $userId,
        int $applicationId,
        int $conversationId,
        int $messageId,
        int $attachmentId,
    ): StreamedResponse {
        $conversation = $this->authorizedConversation($applicationId, $conversationId, $userId);

        $attachment = AppMessageAttachment::query()
            ->whereKey($attachmentId)
            ->whereHas('message', fn ($query) => $query
                ->whereKey($messageId)
                ->where('conversation_id', $conversation->id)
                ->whereNull('deleted_at'))
            ->firstOrFail();

        $disk = Storage::disk($attachment->storage_disk);
        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->download(
            $attachment->storage_path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, max-age=3600, no-transform',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function storeAttachment(UploadedFile $file, int $applicationId, int $conversationId, int $messageId): AppMessageAttachment
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $kind = str_starts_with($mime, 'image/') ? 'image'
            : (str_starts_with($mime, 'video/') ? 'video'
                : (str_starts_with($mime, 'audio/') ? 'audio' : 'file'));
        $uuid = (string) Str::uuid();
        $filename = $uuid.($extension !== '' ? '.'.$extension : '');
        $directory = "messaging/{$applicationId}/{$conversationId}/{$messageId}";
        $path = $file->storeAs($directory, $filename, 'local');

        if (! $path) {
            throw ValidationException::withMessages(['attachments' => ['Não foi possível armazenar um dos arquivos.']]);
        }

        $metadata = [];
        if ($kind === 'image' && is_file($file->getRealPath())) {
            $dimensions = @getimagesize($file->getRealPath());
            if (is_array($dimensions)) {
                $metadata['width'] = (int) ($dimensions[0] ?? 0);
                $metadata['height'] = (int) ($dimensions[1] ?? 0);
            }
        }

        return AppMessageAttachment::query()->create([
            'uuid' => $uuid,
            'message_id' => $messageId,
            'kind' => $kind,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'extension' => $extension !== '' ? mb_substr($extension, 0, 20) : null,
            'mime_type' => mb_substr($mime, 0, 150),
            'file_size' => (int) ($file->getSize() ?: 0),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function authorizedConversation(int $applicationId, int $conversationId, int $userId): AppConversation
    {
        return AppConversation::query()
            ->whereKey($conversationId)
            ->where('application_id', $applicationId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->firstOrFail();
    }

    private function assertConversationWritable(AppConversation $conversation, int $userId, int $applicationId): void
    {
        $otherIds = $conversation->users()
            ->where('users.id', '<>', $userId)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($otherIds as $otherId) {
            if ($this->isBlockedBetween($userId, $otherId, $applicationId)) {
                throw ValidationException::withMessages([
                    'conversation' => ['Não é possível enviar mensagens enquanto houver um bloqueio entre os participantes.'],
                ]);
            }
        }
    }

    private function assertTargetUserAvailable(int $userId, int $targetUserId, int $applicationId): void
    {
        if ($userId === $targetUserId || ! User::query()
            ->whereKey($targetUserId)
            ->whereHas('applications', fn ($query) => $query->where('applications.id', $applicationId))
            ->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Usuário não disponível nesta aplicação.']]);
        }
    }

    private function isBlockedBetween(int $firstUserId, int $secondUserId, int $applicationId): bool
    {
        return DB::table('app_messaging_blocks')
            ->where('application_id', $applicationId)
            ->where(function ($query) use ($firstUserId, $secondUserId) {
                $query->where(function ($pair) use ($firstUserId, $secondUserId) {
                    $pair->where('blocker_user_id', $firstUserId)->where('blocked_user_id', $secondUserId);
                })->orWhere(function ($pair) use ($firstUserId, $secondUserId) {
                    $pair->where('blocker_user_id', $secondUserId)->where('blocked_user_id', $firstUserId);
                });
            })
            ->exists();
    }

    private function blockedUserIds(int $userId, int $applicationId): array
    {
        return DB::table('app_messaging_blocks')
            ->where('application_id', $applicationId)
            ->where(function ($query) use ($userId) {
                $query->where('blocker_user_id', $userId)->orWhere('blocked_user_id', $userId);
            })
            ->get(['blocker_user_id', 'blocked_user_id'])
            ->map(fn ($row) => (int) ($row->blocker_user_id === $userId ? $row->blocked_user_id : $row->blocker_user_id))
            ->unique()
            ->values()
            ->all();
    }

    private function participantIds(AppConversation $conversation): array
    {
        return $conversation->users()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
        $mutedUntil = $this->dateIso($current?->pivot?->muted_until);

        return [
            'id' => (int) $conversation->id,
            'type' => $conversation->type,
            'application_id' => (int) $conversation->application_id,
            'last_message_at' => optional($conversation->last_message_at)->toISOString(),
            'unread_count' => (int) ($current?->pivot?->unread_count ?? 0),
            'last_read_at' => $this->dateIso($current?->pivot?->last_read_at),
            'archived_at' => $this->dateIso($current?->pivot?->archived_at),
            'muted_until' => $mutedUntil,
            'pinned_at' => $this->dateIso($current?->pivot?->pinned_at),
            'is_archived' => $current?->pivot?->archived_at !== null,
            'is_pinned' => $current?->pivot?->pinned_at !== null,
            'is_muted' => $mutedUntil !== null && Carbon::parse($mutedUntil)->isFuture(),
            'is_blocked' => $others->contains(fn (User $person) => $this->isBlockedBetween($currentUserId, (int) $person->id, (int) $conversation->application_id)),
            'participants' => $users->map(fn (User $person) => array_merge(AppMessagePayload::person($person), [
                'last_read_at' => $this->dateIso($person->pivot?->last_read_at),
            ]))->values(),
            'other_users' => $others->map(fn (User $person) => AppMessagePayload::person($person))->values(),
            'last_message' => $conversation->lastMessage ? AppMessagePayload::make($conversation->lastMessage) : null,
        ];
    }

    private function loadConversationRelations(AppConversation $conversation): AppConversation
    {
        return $conversation->load([
            'users:id,first_name,last_name,user_name,email,avatar,city,uf,about',
            'lastMessage.sender:id,first_name,last_name,user_name,avatar',
            'lastMessage.replyTo.sender:id,first_name,last_name,user_name,avatar',
            'lastMessage.attachments',
            'lastMessage.reactions.user:id,first_name,last_name,user_name,avatar',
        ]);
    }

    private function messageRelations(): array
    {
        return [
            'sender:id,first_name,last_name,user_name,avatar',
            'replyTo.sender:id,first_name,last_name,user_name,avatar',
            'attachments',
            'reactions.user:id,first_name,last_name,user_name,avatar',
        ];
    }

    private function dateIso(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->toISOString()
            : Carbon::parse((string) $value)->toISOString();
    }
}
