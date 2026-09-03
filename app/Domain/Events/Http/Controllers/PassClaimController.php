<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PassClaimController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function claim(Request $request, int $ticketId)
    {
        $user = $request->user(); $alreadyIssued = false;
        $pass = DB::transaction(function () use ($ticketId, $user, &$alreadyIssued) {
            $ticketSnapshot = Ticket::query()->where('app_id', $this->context->id())->select(['id','event_id'])->findOrFail($ticketId);
            $event = Event::query()->where('app_id', $this->context->id())->with('production')->lockForUpdate()->findOrFail($ticketSnapshot->event_id);
            $ticket = Ticket::query()->where('app_id', $this->context->id())->where('event_id', $event->id)->lockForUpdate()->findOrFail($ticketId);

            abort_unless($event->production && (int) $event->production->app_id === $this->context->id() && ! $event->is_cancelled && $event->is_published && ! $event->is_private, 422, 'Este evento não está disponível para retirada pública de cortesias.');
            abort_if((float) $ticket->price > 0, 422, 'Este ingresso não é uma cortesia gratuita.');
            abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, 'O prazo para retirada desta cortesia terminou.');

            $existing = EventPass::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->first();
            if ($existing) { $alreadyIssued = true; return $existing->load(['ticket','event.production']); }

            $issuedForBatch = EventPass::query()->where('ticket_id', $ticket->id)->count();
            abort_if((int) $ticket->quantity <= 0 || $issuedForBatch >= (int) $ticket->quantity, 422, 'As cortesias deste lote estão esgotadas.');
            if ($event->max_attendees !== null) {
                $issuedForEvent = EventPass::query()->where('event_id', $event->id)->count();
                abort_if($issuedForEvent >= (int) $event->max_attendees, 422, 'A capacidade máxima deste evento foi atingida.');
            }

            return EventPass::create([
                'ticket_id' => $ticket->id, 'event_id' => $event->id, 'user_id' => $user->id,
                'holder_name' => trim((string) ($user->first_name ?? $user->name ?? 'Participante')),
                'holder_email' => strtolower(trim((string) $user->email)),
                'token' => 'PASS-' . Str::upper(Str::random(16)) . '-' . Str::uuid(), 'status' => 'issued',
            ])->load(['ticket','event.production']);
        }, 3);

        $this->registerParticipation((int) $user->id, 'participant');
        if (! $alreadyIssued && $pass->event) {
            $this->notifications->sendToUser($this->context->id(), $user->id, [
                'type' => 'ticket_issued', 'title' => 'Seu ingresso está pronto',
                'message' => 'O ingresso para ' . $pass->event->title . ' foi emitido. Abra sua carteira para acessar o QR Code.',
                'reference_type' => 'event_pass', 'reference_id' => $pass->id,
                'reference_url' => '/passes/' . $pass->id,
                'data' => ['event_id' => $pass->event_id, 'ticket_id' => $pass->ticket_id],
            ]);
        }

        return response()->json(['message' => $alreadyIssued ? 'Você já possui esta cortesia.' : 'Cortesia retirada com sucesso.', 'pass' => $pass, 'already_issued' => $alreadyIssued], $alreadyIssued ? 200 : 201);
    }

    private function registerParticipation(int $userId, string $role): void
    {
        $existing = DB::table('application_user')->where('application_id', $this->context->id())->where('user_id', $userId)->first();
        $priority = ['participant'=>10,'staff'=>20,'promoter'=>30,'producer'=>40,'admin'=>50];
        $effectiveRole = ($priority[(string) ($existing->role ?? '')] ?? 0) > ($priority[$role] ?? 0) ? (string) $existing->role : $role;
        if ($existing) {
            DB::table('application_user')->where('application_id', $this->context->id())->where('user_id', $userId)->update(['role'=>$effectiveRole,'status'=>'active','updated_at'=>now()]);
            return;
        }
        DB::table('application_user')->insert(['application_id'=>$this->context->id(),'user_id'=>$userId,'role'=>$effectiveRole,'status'=>'active','joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }
}
