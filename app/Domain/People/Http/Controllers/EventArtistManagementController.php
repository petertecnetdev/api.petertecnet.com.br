<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\ArtistInvitationWorkflowService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EventArtistManagementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ArtistInvitationWorkflowService $invitations,
    ) {}

    public function update(Request $request, int $eventId, int $artistId)
    {
        $data = $request->validate([
            'participation_type' => 'sometimes|required|string|max:80',
            'description' => 'sometimes|nullable|string|max:2000',
            'sort_order' => 'sometimes|integer|min:0|max:1000',
            'scheduled_at' => 'sometimes|nullable|date',
            'stage' => 'sometimes|nullable|string|max:160',
            'is_headliner' => 'sometimes|boolean',
            'fee_cents' => 'sometimes|nullable|integer|min:0|max:9999999999',
            'payment_status' => 'sometimes|nullable|in:not_applicable,pending,partially_paid,paid,cancelled',
            'private_notes' => 'sometimes|nullable|string|max:5000',
        ]);

        return response()->json(
            $this->invitations->updateParticipation(
                $this->context->id(),
                $eventId,
                $artistId,
                $request->user(),
                $data
            )
        );
    }

    public function reorder(Request $request, int $eventId)
    {
        $event=$this->ownedEvent($eventId,$request->user());
        $data=$request->validate(['artists'=>'required|array|min:1|max:300','artists.*.artist_id'=>'required|integer','artists.*.sort_order'=>'required|integer|min:0|max:1000']);
        DB::transaction(function()use($event,$data){
            foreach($data['artists'] as $row){
                DB::table('event_artist')->where('app_id',$this->context->id())->where('event_id',$event->id)->where('artist_id',$row['artist_id'])->update(['sort_order'=>$row['sort_order'],'updated_at'=>now()]);
            }
        });
        return response()->json(['message'=>'Ordem do line-up atualizada.','artists'=>$event->artists()->orderBy('event_artist.sort_order')->get()]);
    }

    private function ownedEvent(int $id,User $user):Event
    {
        $event=Event::query()->where('app_id',$this->context->id())->with('production')->findOrFail($id);
        abort_unless($event->production&&($user->hasProfile('Administrador')||(int)$event->production->user_id===(int)$user->id),403,'Você não pode gerenciar este evento.');
        return $event;
    }
}
