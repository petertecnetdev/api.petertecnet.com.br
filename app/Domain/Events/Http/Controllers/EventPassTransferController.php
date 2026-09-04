<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\EventPass;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventPassTransferController extends Controller
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(private readonly ApplicationContext $context) {}

    public function transfer(Request $request, int $passId): JsonResponse
    {
        $sender = $request->user();
        $appId = $this->context->id();
        $data = $request->validate([
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [
            'recipient_email.required' => 'Informe o e-mail da pessoa que receberá o ingresso.',
            'recipient_email.email' => 'Informe um e-mail válido.',
        ]);

        $recipientEmail = Str::lower(trim((string) $data['recipient_email']));
        $recipient = User::query()
            ->whereRaw('LOWER(email) = ?', [$recipientEmail])
            ->first();

        abort_unless(
            $recipient,
            422,
            'Não foi possível transferir para esse e-mail. Confirme se a pessoa já possui uma conta Cutinapp.'
        );
        abort_if(
            (int) $recipient->id === (int) $sender->id,
            422,
            'O ingresso já está vinculado à sua conta.'
        );

        $result = DB::transaction(function () use ($request, $sender, $recipient, $recipientEmail, $appId, $passId) {
            $pass = EventPass::query()
                ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
                ->with(['event', 'ticket'])
                ->lockForUpdate()
                ->findOrFail($passId);

            abort_unless(
                (int) $pass->user_id === (int) $sender->id,
                403,
                'Somente o titular atual pode transferir este ingresso.'
            );
            abort_if(
                in_array((string) $pass->status, self::INVALID_PASS_STATUSES, true),
                422,
                'Este ingresso não está disponível para transferência.'
            );
            abort_if(
                $pass->checked_in_at !== null || (string) $pass->status === 'checked_in',
                422,
                'Ingressos já utilizados não podem ser transferidos.'
            );
            abort_if(
                ! $pass->event || $pass->event->is_cancelled,
                422,
                'Ingressos de eventos cancelados não podem ser transferidos.'
            );
            abort_if(
                $pass->event->end_date && now()->gt($pass->event->end_date),
                422,
                'Este evento já terminou e o ingresso não pode mais ser transferido.'
            );

            $previousToken = (string) $pass->token;
            $newToken = 'PASS-'.Str::upper(Str::random(16)).'-'.Str::uuid();
            $recipientName = trim(implode(' ', array_filter([
                $recipient->first_name,
                $recipient->last_name,
            ]))) ?: ($recipient->user_name ?: $recipientEmail);
            $transferredAt = now();

            $transferId = DB::table('event_pass_transfers')->insertGetId([
                'event_pass_id' => $pass->id,
                'event_id' => $pass->event_id,
                'ticket_id' => $pass->ticket_id,
                'from_user_id' => $sender->id,
                'to_user_id' => $recipient->id,
                'from_holder_name' => $pass->holder_name,
                'from_holder_email' => $pass->holder_email,
                'to_holder_name' => $recipientName,
                'to_holder_email' => $recipientEmail,
                'previous_token_hash' => hash('sha256', $previousToken),
                'new_token_hash' => hash('sha256', $newToken),
                'transferred_at' => $transferredAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => $transferredAt,
                'updated_at' => $transferredAt,
            ]);

            $pass->forceFill([
                'user_id' => $recipient->id,
                'holder_name' => $recipientName,
                'holder_email' => $recipientEmail,
                'token' => $newToken,
            ])->save();

            return [
                'id' => (int) $transferId,
                'pass_id' => (int) $pass->id,
                'event_id' => (int) $pass->event_id,
                'ticket_id' => (int) $pass->ticket_id,
                'recipient_id' => (int) $recipient->id,
                'recipient_name' => $recipientName,
                'recipient_email' => $recipientEmail,
                'event_title' => (string) ($pass->event?->title ?: 'Evento'),
                'transferred_at' => $transferredAt->toISOString(),
            ];
        });

        $this->registerParticipation($appId, (int) $recipient->id);

        AppNotification::create([
            'app_id' => $appId,
            'user_id' => (int) $recipient->id,
            'type' => 'ticket_transfer_received',
            'title' => 'Você recebeu um ingresso',
            'message' => 'Um ingresso para '.$result['event_title'].' foi transferido para você.',
            'reference_type' => 'event_pass',
            'reference_id' => $result['pass_id'],
            'reference_url' => '/passes/'.$result['pass_id'],
            'data' => [
                'transfer_id' => $result['id'],
                'event_id' => $result['event_id'],
                'ticket_id' => $result['ticket_id'],
                'pass_id' => $result['pass_id'],
                'from_user_id' => (int) $sender->id,
            ],
        ]);

        AppNotification::create([
            'app_id' => $appId,
            'user_id' => (int) $sender->id,
            'type' => 'ticket_transfer_sent',
            'title' => 'Ingresso transferido',
            'message' => 'Seu ingresso para '.$result['event_title'].' foi transferido com sucesso.',
            'reference_type' => 'event_pass_transfer',
            'reference_id' => $result['id'],
            'reference_url' => '/passes',
            'data' => [
                'transfer_id' => $result['id'],
                'event_id' => $result['event_id'],
                'ticket_id' => $result['ticket_id'],
                'pass_id' => $result['pass_id'],
                'to_user_id' => $result['recipient_id'],
            ],
        ]);

        return response()->json([
            'message' => 'Ingresso transferido com sucesso. O QR Code anterior foi invalidado.',
            'transfer' => $result,
        ]);
    }

    private function registerParticipation(int $appId, int $userId): void
    {
        $existing = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $priority = [
                'participant' => 10,
                'staff' => 20,
                'promoter' => 30,
                'producer' => 40,
                'admin' => 50,
            ];
            $existingRole = (string) ($existing->role ?? '');
            $effectiveRole = ($priority[$existingRole] ?? 0) > $priority['participant']
                ? $existingRole
                : 'participant';

            DB::table('application_user')
                ->where('application_id', $appId)
                ->where('user_id', $userId)
                ->update([
                    'role' => $effectiveRole,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('application_user')->insert([
            'application_id' => $appId,
            'user_id' => $userId,
            'role' => 'participant',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
