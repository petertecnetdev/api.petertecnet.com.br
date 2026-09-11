<?php

namespace Tests\Feature;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Domain\Messaging\Services\MessageEngagementService;
use App\Domain\Messaging\Services\MessagingService;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MessagingEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_message_notifies_recipient_and_reopens_archived_conversation(): void
    {
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
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'conversation_id' => $conversationId,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'archived_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $notifications = Mockery::mock(AppNotificationService::class);
        $notifications
            ->shouldReceive('sendToUser')
            ->once()
            ->with(
                (int) $application->id,
                (int) $recipient->id,
                Mockery::on(function (array $payload) use ($conversationId): bool {
                    return ($payload['type'] ?? null) === 'direct_message'
                        && ($payload['title'] ?? null) === 'Peter Tecnet'
                        && ($payload['message'] ?? null) === 'Olá Maria, tudo bem?'
                        && ($payload['reference_type'] ?? null) === 'conversation'
                        && (int) ($payload['reference_id'] ?? 0) === $conversationId
                        && ($payload['reference_url'] ?? null) === '/messages?conversation='.$conversationId
                        && (int) ($payload['data']['conversation_id'] ?? 0) === $conversationId;
                })
            )
            ->andReturn(new AppNotification());

        $engagement = Mockery::mock(MessageEngagementService::class);
        $engagement->shouldReceive('markResponse')->once()->with($conversationId, (int) $sender->id);
        $engagement->shouldReceive('queueMessage')->once()->with(Mockery::type('int'), (int) $recipient->id);

        $service = new MessagingService($context, app(ManagedFileStorageService::class), $notifications, $engagement);
        $message = $service->send($conversationId, (int) $sender->id, [
            'body' => 'Olá Maria, tudo bem?',
            'type' => 'text',
        ]);

        $this->assertSame($conversationId, $message['conversation_id']);
        $this->assertSame((int) $sender->id, $message['sender_user_id']);
        $this->assertSame('Olá Maria, tudo bem?', $message['body']);

        $this->assertNull(
            DB::table('conversation_participants')
                ->where('conversation_id', $conversationId)
                ->where('user_id', $recipient->id)
                ->value('archived_at')
        );
    }

    public function test_notification_failure_never_rolls_back_direct_message(): void
    {
        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
        ]);

        $context = app(ApplicationContext::class);
        $context->set($application);

        $sender = User::factory()->create(['user_name' => 'sender']);
        $recipient = User::factory()->create(['user_name' => 'recipient']);

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
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'conversation_id' => $conversationId,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $notifications = Mockery::mock(AppNotificationService::class);
        $notifications
            ->shouldReceive('sendToUser')
            ->once()
            ->andThrow(new RuntimeException('Notification transport unavailable'));

        $engagement = Mockery::mock(MessageEngagementService::class);
        $engagement->shouldReceive('markResponse')->once()->with($conversationId, (int) $sender->id);
        $engagement->shouldReceive('queueMessage')->once()->with(Mockery::type('int'), (int) $recipient->id);

        $service = new MessagingService($context, app(ManagedFileStorageService::class), $notifications, $engagement);

        try {
            $service->send($conversationId, (int) $sender->id, [
                'body' => 'Mensagem importante',
                'type' => 'text',
            ]);
            $this->fail('A falha simulada do transporte de notificação deveria ser propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification transport unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_user_id' => $sender->id,
            'body' => 'Mensagem importante',
        ]);
    }
}
