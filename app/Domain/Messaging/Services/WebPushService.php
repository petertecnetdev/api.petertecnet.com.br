<?php

namespace App\Domain\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class WebPushService
{
    public function subscribe(int $appId, int $userId, array $subscription, ?string $userAgent = null): array
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $publicKey = trim((string) ($subscription['keys']['p256dh'] ?? $subscription['public_key'] ?? ''));
        $authToken = trim((string) ($subscription['keys']['auth'] ?? $subscription['auth_token'] ?? ''));
        abort_if($endpoint === '' || $publicKey === '' || $authToken === '', 422, 'Assinatura de push inválida.');

        $hash = hash('sha256', $endpoint);
        DB::table('web_push_subscriptions')->updateOrInsert(
            ['app_id' => $appId, 'user_id' => $userId, 'endpoint_hash' => $hash],
            [
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => trim((string) ($subscription['contentEncoding'] ?? $subscription['content_encoding'] ?? 'aes128gcm')) ?: 'aes128gcm',
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'failure_count' => 0,
                'disabled_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return ['subscribed' => true, 'endpoint_hash' => $hash];
    }

    public function unsubscribe(int $appId, int $userId, string $endpoint): void
    {
        DB::table('web_push_subscriptions')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('endpoint_hash', hash('sha256', trim($endpoint)))
            ->delete();
    }

    public function publicKey(): ?string
    {
        $key = trim((string) config('services.webpush.public_key'));
        return $key !== '' ? $key : null;
    }

    public function sendToUser(int $appId, int $userId, array $payload): bool
    {
        $publicKey = trim((string) config('services.webpush.public_key'));
        $privateKey = trim((string) config('services.webpush.private_key'));
        $subject = trim((string) config('services.webpush.subject'));

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            Log::notice('Web Push não configurado; notificação push ignorada.', ['app_id' => $appId, 'user_id' => $userId]);
            return false;
        }

        $subscriptions = DB::table('web_push_subscriptions')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->whereNull('disabled_at')
            ->get();

        if ($subscriptions->isEmpty()) {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $sent = false;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $row) {
            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $row->endpoint,
                        'publicKey' => $row->public_key,
                        'authToken' => $row->auth_token,
                        'contentEncoding' => $row->content_encoding ?: 'aes128gcm',
                    ]),
                    $json,
                    ['TTL' => 300, 'urgency' => 'high']
                );

                if ($report->isSuccess()) {
                    $sent = true;
                    DB::table('web_push_subscriptions')->where('id', $row->id)->update([
                        'last_used_at' => now(),
                        'failure_count' => 0,
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                $expired = $report->isSubscriptionExpired();
                DB::table('web_push_subscriptions')->where('id', $row->id)->update([
                    'failure_count' => DB::raw('failure_count + 1'),
                    'disabled_at' => $expired ? now() : null,
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                DB::table('web_push_subscriptions')->where('id', $row->id)->update([
                    'failure_count' => DB::raw('failure_count + 1'),
                    'updated_at' => now(),
                ]);
                Log::warning('Falha ao enviar Web Push.', [
                    'app_id' => $appId,
                    'user_id' => $userId,
                    'subscription_id' => $row->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
