<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappPassClaimController extends Controller
{
    private const APP = 'cutinapp';

    public function claim(Request $request, int $ticketId)
    {
        $user = $this->requestUser($request);
        $application = $this->application();
        $alreadyIssued = false;

        $pass = DB::transaction(function () use ($ticketId, $user, $application, &$alreadyIssued) {
            $ticketSnapshot = Ticket::query()
                ->where('app_id', $application->id)
                ->where('app_slug', self::APP)
                ->select(['id', 'event_id'])
                ->findOrFail($ticketId);

            // Every claim for the same event locks the same event row first.
            // This serializes claims across different ticket batches so the
            // event-wide capacity cannot be exceeded under concurrency.
            $event = Event::query()
                ->where('app_id', $application->id)
                ->where('app_slug', self::APP)
                ->with('production')
                ->lockForUpdate()
                ->findOrFail($ticketSnapshot->event_id);

            $ticket = Ticket::query()
                ->where('app_id', $application->id)
                ->where('app_slug', self::APP)
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            abort_unless(
                $event->production
                    && (int) $event->production->app_id === (int) $application->id
                    && $event->production->app_slug === self::APP
                    && ! $event->is_cancelled
                    && $event->is_published
                    && ! $event->is_private,
                422,
                'Este evento não está disponível para retirada pública de cortesias.'
            );
            abort_if((float) $ticket->price > 0, 422, 'Este ingresso não é uma cortesia gratuita.');
            abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, 'O prazo para retirada desta cortesia terminou.');

            $existing = EventPass::query()
                ->where('ticket_id', $ticket->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $alreadyIssued = true;
                return $existing->load(['ticket', 'event.production']);
            }

            $issuedForBatch = EventPass::query()->where('ticket_id', $ticket->id)->count();
            abort_if(
                (int) $ticket->quantity <= 0 || $issuedForBatch >= (int) $ticket->quantity,
                422,
                'As cortesias deste lote estão esgotadas.'
            );

            if ($event->max_attendees !== null) {
                $issuedForEvent = EventPass::query()->where('event_id', $event->id)->count();
                abort_if(
                    $issuedForEvent >= (int) $event->max_attendees,
                    422,
                    'A capacidade máxima deste evento foi atingida.'
                );
            }

            return EventPass::create([
                'ticket_id' => $ticket->id,
                'event_id' => $event->id,
                'user_id' => $user->id,
                'holder_name' => trim((string) ($user->first_name ?? $user->name ?? 'Participante')),
                'holder_email' => strtolower(trim((string) $user->email)),
                'token' => 'CUT-' . Str::upper(Str::random(16)) . '-' . Str::uuid(),
                'status' => 'issued',
            ])->load(['ticket', 'event.production']);
        }, 3);

        $this->registerParticipation($application, $user->id, 'participant');

        return response()->json([
            'message' => $alreadyIssued
                ? 'Você já possui esta cortesia. Abrimos o ingresso já emitido.'
                : 'Cortesia retirada com sucesso.',
            'pass' => $pass,
            'already_issued' => $alreadyIssued,
        ], $alreadyIssued ? 200 : 201);
    }

    private function requestUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());
        abort_if($token === '', 401, 'Sessão inválida ou expirada. Faça login novamente.');

        try {
            $user = JWTAuth::setToken($token)->authenticate();
        } catch (\Throwable) {
            $user = null;
        }

        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }

    private function application(): Application
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless($application, 503, 'A Cutinapp não está registrada corretamente na API. Execute as migrations e tente novamente.');
        return $application;
    }

    private function registerParticipation(Application $application, int $userId, string $role): void
    {
        $existing = DB::table('application_user')
            ->where('application_id', $application->id)
            ->where('user_id', $userId)
            ->first();

        $priority = ['participant' => 10, 'staff' => 20, 'promoter' => 30, 'producer' => 40, 'admin' => 50];
        $existingRole = (string) ($existing->role ?? '');
        $effectiveRole = ($priority[$existingRole] ?? 0) > ($priority[$role] ?? 0) ? $existingRole : $role;

        if ($existing) {
            DB::table('application_user')
                ->where('application_id', $application->id)
                ->where('user_id', $userId)
                ->update([
                    'role' => $effectiveRole,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('application_user')->insert([
            'application_id' => $application->id,
            'user_id' => $userId,
            'role' => $effectiveRole,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
