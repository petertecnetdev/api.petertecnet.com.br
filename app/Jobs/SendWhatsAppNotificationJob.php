<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\WhatsApp\WhatsAppCloudProvider;
use App\Services\WhatsApp\WhatsAppProviderException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public int $timeout = 30;
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $deliveryId,
        private readonly string $phone,
        private readonly string $template,
        private readonly string $language,
        private readonly array $components = [],
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'whatsapp-delivery:'.$this->deliveryId;
    }

    public function handle(WhatsAppCloudProvider $provider): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery || $delivery->provider_message_id || in_array($delivery->status, ['SENT', 'DELIVERED', 'READ'], true)) {
            return;
        }

        $delivery->increment('attempt_count');

        try {
            $messageId = $provider->sendTemplate($this->phone, $this->template, $this->language, $this->components);
            $delivery->forceFill([
                'provider_message_id' => $messageId,
                'status' => 'SENT',
                'sent_at' => now(),
                'failed_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (WhatsAppProviderException $e) {
            $final = ! $e->retryable || $this->attempts() >= $this->tries;
            $delivery->forceFill([
                'status' => $final ? 'FAILED' : 'QUEUED',
                'failed_at' => $final ? now() : null,
                'error_code' => $e->providerCode,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            if ($e->retryable && ! $final) {
                throw $e;
            }
        }
    }
}
