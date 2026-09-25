<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\WhatsApp\WhatsAppCloudProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SendWhatsAppNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public int $uniqueFor = 3600;
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $deliveryId, public array $components = [])
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'whatsapp-delivery:'.$this->deliveryId;
    }

    public function handle(WhatsAppCloudProvider $provider): void
    {
        $delivery = NotificationDelivery::query()->findOrFail($this->deliveryId);

        if (in_array($delivery->status, ['SENT', 'DELIVERED', 'READ'], true) || $delivery->provider_message_id) {
            return;
        }

        $delivery->increment('attempt_count');

        try {
            $response = $provider->sendTemplate(
                (string) $delivery->destination_e164,
                (string) $delivery->template_name,
                (string) $delivery->locale,
                $this->components
            );

            $messageId = trim((string) data_get($response, 'messages.0.id'));
            if ($messageId === '') {
                throw new \RuntimeException('Meta não retornou provider_message_id.');
            }

            $delivery->forceFill([
                'provider_message_id' => $messageId,
                'status' => 'SENT',
                'sent_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $e) {
            $finalAttempt = $this->attempts() >= $this->tries;
            $delivery->forceFill([
                'status' => $finalAttempt ? 'FAILED' : 'QUEUED',
                'failed_at' => $finalAttempt ? now() : null,
                'error_code' => class_basename($e),
                'error_message' => Str::limit($e->getMessage(), 500),
            ])->save();

            throw $e;
        }
    }
}
