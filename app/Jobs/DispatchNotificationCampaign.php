<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DispatchNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(public int $campaignId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $campaign = NotificationCampaign::find($this->campaignId);
        if (! $campaign || in_array($campaign->status, ['sent', 'completed', 'cancelled'], true)) {
            return;
        }

        $campaign->update(['status' => 'sending']);
        $channels = collect($campaign->channels ?: ['in_app'])->unique()->values();
        $recipientIds = collect($campaign->recipient_user_ids ?: [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $delivered = 0;
        $failed = 0;
        $emailDelivered = 0;
        $inAppDelivered = 0;

        User::query()->whereIn('id', $recipientIds)->select(['id', 'email'])->orderBy('id')->chunkById(250, function ($users) use ($campaign, $channels, &$delivered, &$failed, &$emailDelivered, &$inAppDelivered) {
            foreach ($users as $user) {
                $userSucceeded = false;

                if ($channels->contains('in_app')) {
                    try {
                        $applicationIds = $campaign->app_id
                            ? collect([(int) $campaign->app_id])
                            : DB::table('application_user')->where('user_id', $user->id)->where('status', 'active')->pluck('application_id')->map(fn ($id) => (int) $id)->unique();

                        foreach ($applicationIds as $applicationId) {
                            AppNotification::create([
                                'app_id' => $applicationId,
                                'user_id' => $user->id,
                                'campaign_id' => $campaign->id,
                                'type' => $campaign->type ?: 'general',
                                'title' => $campaign->title,
                                'message' => $campaign->message,
                                'reference_url' => $campaign->reference_url,
                                'data' => array_merge($campaign->data ?: [], ['campaign_id' => $campaign->id]),
                            ]);
                            $inAppDelivered++;
                            $userSucceeded = true;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao criar notificação in-app da campanha.', ['campaign_id' => $campaign->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }

                if ($channels->contains('email') && $user->email) {
                    try {
                        Mail::raw($campaign->message ?: $campaign->title, function ($message) use ($campaign, $user) {
                            $message->to($user->email)->subject($campaign->title);
                        });
                        $emailDelivered++;
                        $userSucceeded = true;
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao enviar e-mail de campanha.', ['campaign_id' => $campaign->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }

                $userSucceeded ? $delivered++ : $failed++;
            }
        });

        $data = $campaign->data ?: [];
        $data['delivery'] = [
            'in_app' => $inAppDelivered,
            'email' => $emailDelivered,
            'push' => 0,
            'push_configured' => false,
        ];

        $campaign->update([
            'data' => $data,
            'status' => $failed > 0 && $delivered === 0 ? 'failed' : 'completed',
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'sent_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
