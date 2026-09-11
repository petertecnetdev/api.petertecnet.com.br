<?php

namespace App\Domain\Messaging\Services;

use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class MessagingSafetyService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function guardDirect(int $userId, int $targetUserId): void
    {
        abort_if($this->blockedEitherWay($userId, $targetUserId), 403, 'Não é possível iniciar esta conversa.');
    }

    public function guardConversationSend(int $conversationId, int $userId): void
    {
        $otherId = DB::table('conversation_participants as cp')
            ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
            ->where('cp.conversation_id', $conversationId)
            ->where('cp.user_id', '<>', $userId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('c.deleted_at')
            ->value('cp.user_id');

        if ($otherId) {
            $this->guardDirect($userId, (int) $otherId);
        }
    }

    public function block(int $userId, int $targetUserId): void
    {
        abort_if($userId === $targetUserId, 422, 'Você não pode bloquear a si mesmo.');
        DB::table('messaging_blocks')->updateOrInsert(
            [
                'app_id' => $this->context->id(),
                'user_id' => $userId,
                'blocked_user_id' => $targetUserId,
            ],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }

    public function unblock(int $userId, int $targetUserId): void
    {
        DB::table('messaging_blocks')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('blocked_user_id', $targetUserId)
            ->delete();
    }

    public function isBlockedByMe(int $userId, int $targetUserId): bool
    {
        return DB::table('messaging_blocks')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('blocked_user_id', $targetUserId)
            ->exists();
    }

    public function report(int $userId, int $targetUserId, ?int $conversationId, string $reason, ?string $details): int
    {
        abort_if($userId === $targetUserId, 422, 'Denúncia inválida.');
        if ($conversationId) {
            $participant = DB::table('conversation_participants as cp')
                ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
                ->where('cp.conversation_id', $conversationId)
                ->where('cp.user_id', $userId)
                ->where('c.app_id', $this->context->id())
                ->whereNull('c.deleted_at')
                ->exists();
            abort_unless($participant, 404, 'Conversa não encontrada.');
        }

        return (int) DB::table('messaging_reports')->insertGetId([
            'app_id' => $this->context->id(),
            'reporter_user_id' => $userId,
            'reported_user_id' => $targetUserId,
            'conversation_id' => $conversationId,
            'reason' => $reason,
            'details' => $details ? trim($details) : null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function blockedEitherWay(int $userId, int $targetUserId): bool
    {
        return DB::table('messaging_blocks')
            ->where('app_id', $this->context->id())
            ->where(function ($query) use ($userId, $targetUserId) {
                $query->where(function ($builder) use ($userId, $targetUserId) {
                    $builder->where('user_id', $userId)->where('blocked_user_id', $targetUserId);
                })->orWhere(function ($builder) use ($userId, $targetUserId) {
                    $builder->where('user_id', $targetUserId)->where('blocked_user_id', $userId);
                });
            })
            ->exists();
    }
}
