<?php

namespace App\Domain\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EventArtistManagementController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function update(Request $request, int $eventId, int $artistId)
    {
        $event=$this->ownedEvent($eventId,$request->user());
        $data=$request->validate([
            'participation_type'=>'sometimes|required|string|max:80',
            'description'=>'sometimes|nullable|string|max:2000',
            'sort_order'=>'sometimes|integer|min:0|max:1000',
            'scheduled_at'=>'sometimes|nullable|date',
            'stage'=>'sometimes|nullable|string|max:160',
            'is_headliner'=>'sometimes|boolean',
            'fee_cents'=>'sometimes|nullable|integer|min:0|max:9999999999',
            'payment_status'=>'sometimes|nullable|in:not_applicable,pending,partially_paid,paid,cancelled',
            'private_notes'=>'sometimes|nullable|string|max:5000',
        ]);
        $pivot=DB::table('event_artist')->where('app_id',$this->context->id())->where('event_id',$event->id)->where('artist_id',$artistId)->first();
        abort_unless($pivot,404,'Participação artística não encontrada.');
        $allowed=collect($data)->only(['participation_type','description','sort_order','scheduled_at','stage','is_headliner','fee_cents','payment_status','private_notes'])->all();
        $allowed['updated_at']=now();
        DB::transaction(function()use($event,$artistId,$pivot,$allowed,$request){
            DB::table('event_artist')->where('event_id',$event->id)->where('artist_id',$artistId)->update($allowed);
            DB::table('artist_audit_logs')->insert(['app_id'=>$this->context->id(),'artist_id'=>$artistId,'event_id'=>$event->id,'actor_user_id'=>$request->user()->id,'action'=>'participation_details_updated','before'=>json_encode((array)$pivot),'after'=>json_encode($allowed),'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['message'=>'Participação atualizada.','artists'=>$event->artists()->orderBy('event_artist.sort_order')->get()]);
    }

    public function reorder(Request $request, int $eventId)
    {
        $event=$this->ownedEvent($eventId,$request->user());
        $data=$request->validate(['artists'=>'required|array|min:1|max:300','artists.*.artist_id'=>'required|integer','artists.*.sort_order'=>'required|integer|min:0|max:1000','artists.*.scheduled_at'=>'sometimes|nullable|date','artists.*.stage'=>'sometimes|nullable|string|max:160']);
        DB::transaction(function()use($event,$data){
            foreach($data['artists'] as $row){
                $updates=['sort_order'=>$row['sort_order'],'updated_at'=>now()];
                if(array_key_exists('scheduled_at',$row))$updates['scheduled_at']=$row['scheduled_at'];
                if(array_key_exists('stage',$row))$updates['stage']=$row['stage'];
                DB::table('event_artist')->where('app_id',$this->context->id())->where('event_id',$event->id)->where('artist_id',$row['artist_id'])->update($updates);
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
