<?php

namespace Tests\Feature;

use App\Domain\Messaging\Services\MessageEngagementService;
use App\Mail\MessageEngagementMail;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageEngagementDirectEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_direct_is_emailed_with_reply_link(): void
    {
        Mail::fake();

        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
        ]);

        $context = app(ApplicationContext::class);
        $context->set($application);

        $sender = User::factory()->create([
            'first_name' => 'Peter',
            'last_name' => 'Tecnet',
            'user_name' => 'peter',
        ]);
        $recipient = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Silva',
            'user_name' => 'maria',
        ]);

        $conversationId = DB::table('conversations')->insertGetId([
            'app_id' => $application->id,
            'type' => 'direct',
            'created_by' => $sender->id,
            'direct_key' => hash('sha256', $sender->id.':'.$recipient->id),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('conversation_participants')->insert([
            [
                'conversation_id' => $conversationId,
                'user_id' => $sender->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'conversation_id' => $conversationId,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('messaging_user_settings')->insert([
            'app_id' => $application->id,
            'user_id' => $recipient->id,
            'email_new_messages' => true,
            'push_new_messages' => false,
            'digest_messages' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messageId = DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender_user_id' => $sender->id,
            'type' => 'text',
            'body' => 'Olá Maria, tudo bem?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $engagement = app(MessageEngagementService::class);
        $engagement->processMessage((int) $messageId, (int) $recipient->id);

        DB::table('messaging_engagement_cycles')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $recipient->id)
            ->update(['next_email_at' => now()->subSecond()]);

        $engagement->sendDueEmail((int) $recipient->id);

        Mail::assertSent(
            MessageEngagementMail::class,
            function (MessageEngagementMail $mail) use ($recipient, $conversationId): bool {
                return $mail->recipient->is($recipient)
                    && $mail->messageCount === 1
                    && str_contains(
                        $mail->actionUrl,
                        '/messages?conversation='.$conversationId
                    );
            }
        );

        $this->assertDatabaseHas('messaging_notification_deliveries', [
            'message_id' => $messageId,
            'user_id' => $recipient->id,
            'email_status' => 'sent',
        ]);
    }
}
