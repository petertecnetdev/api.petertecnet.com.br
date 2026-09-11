<?php

namespace App\Domain\Messaging\Services;

use App\Jobs\DispatchMessageEngagementEmail;
use App\Jobs\ProcessMessageEngagement;
use App\Mail\MessageEngagementMail;
use App\Models\Application;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class MessageEngagementService
{
    private const GROUP_WINDOW_SECONDS = 60;
    private const ACTIVE_POSTPONE_SECONDS = 120;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly WebPushService $webPush,
    ) {}

    public function queueMessage(int $messageId, int $recipientUserId): void
    {
        ProcessMessageEngagement::dispatch(
            $this->context->id(),
            $messageId,
            $recipientUserId,
        )->afterCommit();
    }

    public function processMessage(int $messageId, int $recipientUserId): void
    {
        $message = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.id', $messageId)
            ->where('c.app_id', $this->context->id())
            ->whereNull('m.deleted_at')
            ->select('m.*', 'c.type as conversation_type', 'c.title as conversation_title')
            ->first();

        if (! $message || (int) $message->sender_user_id === $recipientUserId) {
            return;
        }

        if ($message->scheduled_at && Carbon::parse($message->scheduled_at)->isFuture()) {
            return;
        }

        if ($message->expires_at && Carbon::parse($message->expires_at)->isPast()) {
            return;
        }

        DB::table('messaging_notification_deliveries')->insertOrIgnore([
            'app_id' => $this->context->id(),
            'conversation_id' => (int) $message->conversation_id,
            'message_id' => (int) $message->id,
            'user_id' => $recipientUserId,
            'email_status' => 'pending',
            'push_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = DB::table('messaging_notification_deliveries')
            ->where('message_id', $messageId)
            ->where('user_id', $recipientUserId)
            ->first();

        if (! $delivery || $delivery->engagement_cycle_id) {
            return;
        }

        $participant = DB::table('conversation_participants')
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $recipientUserId)
            ->first();

        if (! $participant) {
            $this->skipDelivery((int) $delivery->id, 'not_participant');
            return;
        }

        if ($participant->muted_until && Carbon::parse($participant->muted_until)->isFuture()) {
            $this->skipDelivery((int) $delivery->id, 'conversation_muted');
            return;
        }

        if (($participant->notification_level ?? 'all') === 'none') {
            $this->skipDelivery((int) $delivery->id, 'notifications_disabled');
            return;
        }

        if ($this->blockedEitherWay((int) $message->sender_user_id, $recipientUserId)) {
            $this->skipDelivery((int) $delivery->id, 'blocked');
            return;
        }

        if (($participant->notification_level ?? 'all') === 'mentions'
            && $message->conversation_type === 'group'
            && ! $this->messageMentionsUser($message, $recipientUserId)) {
            $this->skipDelivery((int) $delivery->id, 'mentions_only');
            return;
        }

        $settings = $this->settings($recipientUserId);
        $active = $this->isConversationActive($recipientUserId, (int) $message->conversation_id);
        $cycle = $this->upsertCycle($message, $recipientUserId, $settings, $active);

        DB::table('messaging_notification_deliveries')
            ->where('id', $delivery->id)
            ->update([
                'engagement_cycle_id' => $cycle->id,
                'email_status' => $settings['email_new_messages'] ? 'pending' : 'disabled',
                'push_status' => $settings['push_new_messages'] ? 'pending' : 'disabled',
                'skip_reason' => $active ? 'conversation_active' : null,
                'updated_at' => now(),
            ]);

        if (! $active && $settings['push_new_messages']) {
            $sender = User::query()->find((int) $message->sender_user_id);
            $preview = $settings['include_message_preview']
                ? $this->previewForMessage($message)
                : 'Você recebeu uma nova mensagem.';

            $sent = $this->webPush->sendToUser($this->context->id(), $recipientUserId, [
                'type' => 'direct_message',
                'title' => $sender ? $this->displayName($sender) : 'Nova mensagem',
                'body' => $preview,
                'icon' => $sender?->avatar,
                'url' => '/messages?conversation='.(int) $message->conversation_id,
                'conversation_id' => (int) $message->conversation_id,
                'message_id' => (int) $message->id,
                'tag' => 'conversation-'.(int) $message->conversation_id,
            ]);

            DB::table('messaging_notification_deliveries')
                ->where('id', $delivery->id)
                ->update([
                    'push_status' => $sent ? 'sent' : 'unavailable',
                    'push_sent_at' => $sent ? now() : null,
                    'updated_at' => now(),
                ]);

            if ($sent && ! $cycle->first_push_at) {
                DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
                    'first_push_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function markConversationActive(int $conversationId, int $userId, bool $active): void
    {
        $exists = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->exists();
        abort_unless($exists, 404, 'Conversa não encontrada.');

        $key = $this->activeConversationKey($userId, $conversationId);
        if ($active) {
            Cache::put($key, true, now()->addSeconds(90));
        } else {
            Cache::forget($key);
        }
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $now = now();
        $cycleIds = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->pluck('id');

        if ($cycleIds->isEmpty()) {
            return;
        }

        DB::table('messaging_engagement_cycles')->whereIn('id', $cycleIds)->update([
            'first_read_at' => $now,
            'next_email_at' => null,
            'next_email_kind' => null,
            'status' => 'read',
            'closed_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('messaging_notification_deliveries')
            ->whereIn('engagement_cycle_id', $cycleIds)
            ->update(['read_at' => $now, 'updated_at' => $now]);
    }

    public function markResponse(int $conversationId, int $userId): void
    {
        $cycle = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereIn('status', ['open', 'read'])
            ->whereNull('first_response_at')
            ->orderByDesc('id')
            ->first();

        if (! $cycle) {
            return;
        }

        $now = now();
        DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
            'first_read_at' => $cycle->first_read_at ?: $now,
            'first_response_at' => $now,
            'next_email_at' => null,
            'next_email_kind' => null,
            'status' => 'responded',
            'closed_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('messaging_notification_deliveries')
            ->where('engagement_cycle_id', $cycle->id)
            ->update([
                'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'),
                'responded_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function cancelMessage(int $messageId): void
    {
        $deliveries = DB::table('messaging_notification_deliveries')->where('message_id', $messageId)->get();

        foreach ($deliveries as $delivery) {
            DB::table('messaging_notification_deliveries')->where('id', $delivery->id)->update([
                'email_status' => in_array($delivery->email_status, ['sent', 'disabled'], true) ? $delivery->email_status : 'cancelled',
                'push_status' => in_array($delivery->push_status, ['sent', 'disabled'], true) ? $delivery->push_status : 'cancelled',
                'skip_reason' => 'message_deleted',
                'updated_at' => now(),
            ]);

            if ($delivery->engagement_cycle_id) {
                $this->recalculateCycle((int) $delivery->engagement_cycle_id);
            }
        }
    }

    public function prepareAndDispatchDue(): int
    {
        $this->prepareReminders();

        $userIds = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('status', 'open')
            ->whereNull('first_read_at')
            ->whereNotNull('next_email_at')
            ->where('next_email_at', '<=', now())
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            DispatchMessageEngagementEmail::dispatch($this->context->id(), (int) $userId);
        }

        return $userIds->count();
    }

    public function sendDueEmail(int $userId): void
    {
        $settings = $this->settings($userId);

        $cycles = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->whereNull('first_read_at')
            ->whereNotNull('next_email_at')
            ->where('next_email_at', '<=', now())
            ->orderBy('next_email_at')
            ->limit(20)
            ->get();

        if ($cycles->isEmpty()) {
            return;
        }

        $eligible = collect();

        foreach ($cycles as $cycle) {
            if ($this->cycleAlreadyRead($cycle)) {
                $this->closeCycleAsRead($cycle);
                continue;
            }

            if ($this->isConversationActive($userId, (int) $cycle->conversation_id)) {
                DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
                    'next_email_at' => now()->addSeconds(self::ACTIVE_POSTPONE_SECONDS),
                    'updated_at' => now(),
                ]);
                continue;
            }

            $participant = DB::table('conversation_participants')
                ->where('conversation_id', $cycle->conversation_id)
                ->where('user_id', $userId)
                ->first();

            if (! $participant
                || ($participant->muted_until && Carbon::parse($participant->muted_until)->isFuture())
                || ($participant->notification_level ?? 'all') === 'none') {
                DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
                    'next_email_at' => null,
                    'next_email_kind' => null,
                    'updated_at' => now(),
                ]);
                continue;
            }

            $eligible->push($cycle);
        }

        if ($eligible->isEmpty()) {
            return;
        }

        if (! $settings['email_new_messages']) {
            $cycleIds = $eligible->pluck('id');
            DB::table('messaging_notification_deliveries')->whereIn('engagement_cycle_id', $cycleIds)->where('email_status', 'pending')->update([
                'email_status' => 'disabled',
                'skip_reason' => 'email_disabled',
                'updated_at' => now(),
            ]);
            DB::table('messaging_engagement_cycles')->whereIn('id', $cycleIds)->update([
                'next_email_at' => null,
                'next_email_kind' => null,
                'updated_at' => now(),
            ]);
            return;
        }

        if ($settings['digest_messages']) {
            $this->sendBatch($userId, $eligible, $settings);
            return;
        }

        foreach ($eligible as $cycle) {
            $this->sendBatch($userId, collect([$cycle]), $settings);
        }
    }

    public function trackEmailClick(int $userId, string $token): ?int
    {
        $batch = DB::table('messaging_email_batches')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('token', $token)
            ->first();

        if (! $batch) {
            return null;
        }

        if (! $batch->clicked_at) {
            DB::table('messaging_email_batches')->where('id', $batch->id)->update([
                'clicked_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cycleIds = json_decode((string) $batch->cycle_ids, true) ?: [];
        if (count($cycleIds) !== 1) {
            return null;
        }

        return (int) DB::table('messaging_engagement_cycles')->where('id', (int) $cycleIds[0])->value('conversation_id');
    }

    public function metrics(int $days = 30): array
    {
        $rows = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('created_at', '>=', now()->subDays(max(1, min($days, 365))))
            ->orderByDesc('id')
            ->limit(10000)
            ->get();

        $readLatencies = [];
        $responseLatencies = [];
        foreach ($rows as $row) {
            $start = Carbon::parse($row->first_message_at);
            if ($row->first_read_at) {
                $readLatencies[] = max(0, $start->diffInSeconds(Carbon::parse($row->first_read_at), false));
            }
            if ($row->first_response_at) {
                $responseLatencies[] = max(0, $start->diffInSeconds(Carbon::parse($row->first_response_at), false));
            }
        }

        $emails = DB::table('messaging_email_batches')
            ->where('app_id', $this->context->id())
            ->where('created_at', '>=', now()->subDays(max(1, min($days, 365))))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->first();

        return [
            'period_days' => $days,
            'cycles' => $rows->count(),
            'read_rate' => $rows->count() ? round(count($readLatencies) * 100 / $rows->count(), 1) : 0,
            'response_rate' => $rows->count() ? round(count($responseLatencies) * 100 / $rows->count(), 1) : 0,
            'avg_read_seconds' => $this->average($readLatencies),
            'avg_response_seconds' => $this->average($responseLatencies),
            'median_read_seconds' => $this->median($readLatencies),
            'median_response_seconds' => $this->median($responseLatencies),
            'emails_sent' => (int) ($emails->sent ?? 0),
            'email_clicks' => (int) ($emails->clicked ?? 0),
            'email_click_rate' => (int) ($emails->sent ?? 0) > 0
                ? round((int) ($emails->clicked ?? 0) * 100 / (int) $emails->sent, 1)
                : 0,
        ];
    }

    public function subscribePush(int $userId, array $subscription, ?string $userAgent): array
    {
        return $this->webPush->subscribe($this->context->id(), $userId, $subscription, $userAgent);
    }

    public function unsubscribePush(int $userId, string $endpoint): void
    {
        $this->webPush->unsubscribe($this->context->id(), $userId, $endpoint);
    }

    public function pushPublicKey(): ?string
    {
        return $this->webPush->publicKey();
    }

    private function upsertCycle(object $message, int $recipientUserId, array $settings, bool $active): object
    {
        $cycle = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $recipientUserId)
            ->where('status', 'open')
            ->whereNull('first_read_at')
            ->orderByDesc('id')
            ->first();

        $now = now();

        if (! $cycle) {
            $id = DB::table('messaging_engagement_cycles')->insertGetId([
                'app_id' => $this->context->id(),
                'conversation_id' => (int) $message->conversation_id,
                'user_id' => $recipientUserId,
                'last_sender_user_id' => (int) $message->sender_user_id,
                'first_message_id' => (int) $message->id,
                'last_message_id' => (int) $message->id,
                'message_count' => 1,
                'first_message_at' => $message->created_at,
                'last_message_at' => $message->created_at,
                'next_email_at' => $now->copy()->addSeconds($active ? self::ACTIVE_POSTPONE_SECONDS : self::GROUP_WINDOW_SECONDS),
                'next_email_kind' => 'new_message',
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return DB::table('messaging_engagement_cycles')->where('id', $id)->first();
        }

        $cooldownAt = $cycle->last_email_at
            ? Carbon::parse($cycle->last_email_at)->addMinutes($settings['email_cooldown_minutes'])
            : null;
        $groupAt = $now->copy()->addSeconds($active ? self::ACTIVE_POSTPONE_SECONDS : self::GROUP_WINDOW_SECONDS);
        $nextAt = $cooldownAt && $cooldownAt->greaterThan($groupAt) ? $cooldownAt : $groupAt;

        DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
            'last_sender_user_id' => (int) $message->sender_user_id,
            'last_message_id' => (int) $message->id,
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => $message->created_at,
            'next_email_at' => $nextAt,
            'next_email_kind' => 'new_message',
            'updated_at' => $now,
        ]);

        return DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->first();
    }

    private function prepareReminders(): void
    {
        $cycles = DB::table('messaging_engagement_cycles')
            ->where('app_id', $this->context->id())
            ->where('status', 'open')
            ->whereNull('first_read_at')
            ->whereNull('next_email_at')
            ->whereNotNull('last_email_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($cycles as $cycle) {
            $settings = $this->settings((int) $cycle->user_id);
            if (! $settings['unread_reminders'] || ! $settings['email_new_messages']) {
                continue;
            }

            $lastMessageAt = Carbon::parse($cycle->last_message_at);
            $kind = null;

            if (! $cycle->reminder_1_at && $lastMessageAt->copy()->addMinutes($settings['first_reminder_minutes'])->lte(now())) {
                $kind = 'reminder_1';
            } elseif ($cycle->reminder_1_at
                && ! $cycle->reminder_2_at
                && $lastMessageAt->copy()->addMinutes($settings['second_reminder_minutes'])->lte(now())) {
                $kind = 'reminder_2';
            }

            if ($kind) {
                DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
                    'next_email_at' => now(),
                    'next_email_kind' => $kind,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function sendBatch(int $userId, $cycles, array $settings): void
    {
        $recipient = User::query()->find($userId);
        $application = $this->context->application();
        if (! $recipient || ! trim((string) $recipient->email)) {
            return;
        }

        $kind = $this->highestPriorityKind($cycles->pluck('next_email_kind')->filter()->all());
        $token = (string) Str::uuid();
        $cycleIds = $cycles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $messageCount = (int) $cycles->sum('message_count');

        $batchId = DB::table('messaging_email_batches')->insertGetId([
            'token' => $token,
            'app_id' => $this->context->id(),
            'user_id' => $userId,
            'kind' => $kind,
            'conversation_count' => count($cycleIds),
            'message_count' => $messageCount,
            'cycle_ids' => json_encode($cycleIds),
            'sending_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('messaging_engagement_cycles')->whereIn('id', $cycleIds)->update([
            'next_email_at' => null,
            'updated_at' => now(),
        ]);

        $conversations = $cycles->map(fn ($cycle) => $this->emailConversationPayload($cycle, $settings))->values()->all();
        $query = http_build_query(array_filter([
            'conversation' => count($cycleIds) === 1 ? $conversations[0]['conversation_id'] : null,
            'engagement' => $token,
        ]));
        $actionUrl = rtrim((string) ($application->url ?: 'https://cutinapp.petertecnet.com.br'), '/').'/messages'.($query ? '?'.$query : '');

        try {
            Mail::to($recipient->email)->send(new MessageEngagementMail(
                $recipient,
                $application,
                $conversations,
                $actionUrl,
                $kind,
                $messageCount,
            ));

            $now = now();
            DB::table('messaging_email_batches')->where('id', $batchId)->update([
                'sent_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($cycles as $cycle) {
                $updates = [
                    'last_email_at' => $now,
                    'email_count' => DB::raw('email_count + 1'),
                    'next_email_kind' => null,
                    'updated_at' => $now,
                ];
                if ($kind === 'reminder_1' && ! $cycle->reminder_1_at) {
                    $updates['reminder_1_at'] = $now;
                }
                if ($kind === 'reminder_2' && ! $cycle->reminder_2_at) {
                    $updates['reminder_2_at'] = $now;
                }
                DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update($updates);
            }

            DB::table('messaging_notification_deliveries')
                ->whereIn('engagement_cycle_id', $cycleIds)
                ->where('email_status', 'pending')
                ->update([
                    'email_status' => 'sent',
                    'email_sent_at' => $now,
                    'updated_at' => $now,
                ]);
        } catch (\Throwable $e) {
            DB::table('messaging_email_batches')->where('id', $batchId)->update([
                'failed_at' => now(),
                'failure_message' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            DB::table('messaging_engagement_cycles')->whereIn('id', $cycleIds)->update([
                'next_email_at' => now()->addMinutes(5),
                'next_email_kind' => $kind,
                'updated_at' => now(),
            ]);
            Log::error('Falha ao enviar e-mail de engajamento de mensagens.', [
                'app_id' => $this->context->id(),
                'user_id' => $userId,
                'batch_id' => $batchId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function emailConversationPayload(object $cycle, array $settings): array
    {
        $conversation = DB::table('conversations')->where('id', $cycle->conversation_id)->first();
        $sender = $cycle->last_sender_user_id ? User::query()->find((int) $cycle->last_sender_user_id) : null;
        $message = DB::table('messages')->where('id', $cycle->last_message_id)->first();

        return [
            'conversation_id' => (int) $cycle->conversation_id,
            'sender_name' => $sender ? $this->displayName($sender) : ($conversation?->title ?: 'Nova conversa'),
            'sender_username' => $sender?->user_name,
            'sender_avatar' => $this->absoluteUrl($sender?->avatar),
            'message_count' => (int) $cycle->message_count,
            'preview' => $settings['include_message_preview'] && $message
                ? $this->previewForMessage($message)
                : 'O conteúdo da mensagem está oculto pelas suas preferências de privacidade.',
            'last_message_at' => $cycle->last_message_at,
        ];
    }

    private function settings(int $userId): array
    {
        $row = DB::table('messaging_user_settings')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->first();

        return [
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

    private function skipDelivery(int $deliveryId, string $reason): void
    {
        DB::table('messaging_notification_deliveries')->where('id', $deliveryId)->update([
            'email_status' => 'skipped',
            'push_status' => 'skipped',
            'skip_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    private function cycleAlreadyRead(object $cycle): bool
    {
        $participant = DB::table('conversation_participants')
            ->where('conversation_id', $cycle->conversation_id)
            ->where('user_id', $cycle->user_id)
            ->first();

        if (! $participant?->last_read_at) {
            return false;
        }

        return Carbon::parse($participant->last_read_at)->gte(Carbon::parse($cycle->last_message_at));
    }

    private function closeCycleAsRead(object $cycle): void
    {
        $now = now();
        DB::table('messaging_engagement_cycles')->where('id', $cycle->id)->update([
            'first_read_at' => $cycle->first_read_at ?: $now,
            'next_email_at' => null,
            'next_email_kind' => null,
            'status' => 'read',
            'closed_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('messaging_notification_deliveries')->where('engagement_cycle_id', $cycle->id)->update([
            'read_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function recalculateCycle(int $cycleId): void
    {
        $cycle = DB::table('messaging_engagement_cycles')->where('id', $cycleId)->first();
        if (! $cycle || $cycle->status !== 'open') {
            return;
        }

        $messages = DB::table('messaging_notification_deliveries as d')
            ->join('messages as m', 'm.id', '=', 'd.message_id')
            ->where('d.engagement_cycle_id', $cycleId)
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get(['m.id', 'm.sender_user_id', 'm.created_at']);

        if ($messages->isEmpty()) {
            DB::table('messaging_engagement_cycles')->where('id', $cycleId)->update([
                'status' => 'cancelled',
                'next_email_at' => null,
                'next_email_kind' => null,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        $first = $messages->first();
        $last = $messages->last();
        DB::table('messaging_engagement_cycles')->where('id', $cycleId)->update([
            'first_message_id' => $first->id,
            'last_message_id' => $last->id,
            'last_sender_user_id' => $last->sender_user_id,
            'message_count' => $messages->count(),
            'first_message_at' => $first->created_at,
            'last_message_at' => $last->created_at,
            'updated_at' => now(),
        ]);
    }

    private function isConversationActive(int $userId, int $conversationId): bool
    {
        return (bool) Cache::get($this->activeConversationKey($userId, $conversationId), false);
    }

    private function activeConversationKey(int $userId, int $conversationId): string
    {
        return 'messaging:active:'.$this->context->id().':'.$userId.':'.$conversationId;
    }

    private function messageMentionsUser(object $message, int $userId): bool
    {
        $user = User::query()->find($userId);
        if ($user?->user_name && str_contains(mb_strtolower((string) $message->body), '@'.mb_strtolower($user->user_name))) {
            return true;
        }

        $metadata = json_decode((string) ($message->metadata ?? ''), true);
        $mentions = collect(is_array($metadata['mentions'] ?? null) ? $metadata['mentions'] : [])
            ->map(fn ($id) => (int) $id);

        return $mentions->contains($userId);
    }

    private function blockedEitherWay(int $one, int $two): bool
    {
        return DB::table('messaging_blocks')
            ->where('app_id', $this->context->id())
            ->where('kind', 'block')
            ->where(function ($query) use ($one, $two) {
                $query->where(fn ($nested) => $nested->where('blocker_user_id', $one)->where('blocked_user_id', $two))
                    ->orWhere(fn ($nested) => $nested->where('blocker_user_id', $two)->where('blocked_user_id', $one));
            })
            ->exists();
    }

    private function previewForMessage(object $message): string
    {
        $body = trim((string) ($message->body ?? ''));
        if ($body !== '') {
            return Str::limit((string) preg_replace('/\s+/', ' ', $body), 180);
        }

        return match ((string) ($message->type ?? 'text')) {
            'image' => 'Enviou uma imagem.',
            'video' => 'Enviou um vídeo.',
            'audio' => 'Enviou um áudio.',
            'file' => 'Enviou um arquivo.',
            'location' => 'Compartilhou uma localização.',
            'share' => 'Compartilhou algo com você.',
            default => 'Enviou uma nova mensagem.',
        };
    }

    private function highestPriorityKind(array $kinds): string
    {
        if (in_array('reminder_2', $kinds, true)) return 'reminder_2';
        if (in_array('reminder_1', $kinds, true)) return 'reminder_1';
        return 'new_message';
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->first_name.' '.(string) $user->last_name);
        return $name !== '' ? $name : ((string) $user->user_name ?: 'Usuário');
    }

    private function absoluteUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        return rtrim((string) ($this->context->application()->url ?: 'https://cutinapp.petertecnet.com.br'), '/').'/'.ltrim($value, '/');
    }

    private function average(array $values): int
    {
        return $values ? (int) round(array_sum($values) / count($values)) : 0;
    }

    private function median(array $values): int
    {
        if (! $values) return 0;
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 ? (int) $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
