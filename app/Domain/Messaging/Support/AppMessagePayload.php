<?php

namespace App\Domain\Messaging\Support;

use App\Models\AppMessage;
use App\Models\User;

final class AppMessagePayload
{
    public static function make(AppMessage $message): array
    {
        $message->loadMissing([
            'sender:id,first_name,last_name,user_name,avatar',
            'replyTo.sender:id,first_name,last_name,user_name,avatar',
            'attachments',
            'reactions.user:id,first_name,last_name,user_name,avatar',
        ]);

        $deleted = $message->deleted_at !== null;

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_user_id' => (int) $message->sender_user_id,
            'reply_to_message_id' => $message->reply_to_message_id ? (int) $message->reply_to_message_id : null,
            'type' => $message->type,
            'client_token' => $message->client_token,
            'body' => $deleted ? null : $message->body,
            'metadata' => $deleted ? null : $message->metadata,
            'created_at' => optional($message->created_at)->toISOString(),
            'edited_at' => optional($message->edited_at)->toISOString(),
            'deleted_at' => optional($message->deleted_at)->toISOString(),
            'is_deleted' => $deleted,
            'sender' => $message->sender ? self::person($message->sender) : null,
            'reply_to' => $message->replyTo ? self::reply($message->replyTo) : null,
            'attachments' => $deleted ? [] : $message->attachments->map(static fn ($attachment) => [
                'id' => (int) $attachment->id,
                'uuid' => $attachment->uuid,
                'kind' => $attachment->kind,
                'original_name' => $attachment->original_name,
                'extension' => $attachment->extension,
                'mime_type' => $attachment->mime_type,
                'file_size' => (int) $attachment->file_size,
                'metadata' => $attachment->metadata,
            ])->values(),
            'reactions' => $deleted ? [] : $message->reactions
                ->groupBy('emoji')
                ->map(static fn ($group, $emoji) => [
                    'emoji' => (string) $emoji,
                    'count' => $group->count(),
                    'user_ids' => $group->pluck('user_id')->map(static fn ($id) => (int) $id)->values(),
                    'users' => $group->map(static fn ($reaction) => $reaction->user ? self::person($reaction->user) : null)->filter()->values(),
                ])
                ->values(),
        ];
    }

    private static function reply(AppMessage $message): array
    {
        $deleted = $message->deleted_at !== null;

        return [
            'id' => (int) $message->id,
            'sender_user_id' => (int) $message->sender_user_id,
            'body' => $deleted ? null : $message->body,
            'type' => $message->type,
            'is_deleted' => $deleted,
            'sender' => $message->sender ? self::person($message->sender) : null,
        ];
    }

    public static function person(User $person): array
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
