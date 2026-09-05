<?php

namespace App\Domain\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserActivityProfileController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function overview(Request $request)
    {
        $user=$request->user();$appId=$this->context->id();$now=now();$relations=['production:id,app_id,name,slug,logo','artists:id,app_id,slug,stage_name,photo'];
        $ticketEventIds=EventPass::where('user_id',$user->id)->whereHas('event',fn($q)=>$q->where('app_id',$appId))->pluck('event_id')->unique()->values();
        $interestedIds=DB::table('event_engagements')->where(['app_id'=>$appId,'user_id'=>$user->id,'is_interested'=>true])->pluck('event_id');
        $favoriteIds=DB::table('event_engagements')->where(['app_id'=>$appId,'user_id'=>$user->id,'is_favorite'=>true])->pluck('event_id');
        $base=fn()=>Event::query()->where('app_id',$appId)->with($relations);
        $ticketUpcoming=$base()->whereIn('id',$ticketEventIds)->where('end_date','>',$now)->orderBy('start_date')->limit(24)->get();
        $ticketPast=$base()->whereIn('id',$ticketEventIds)->where('end_date','<=',$now)->orderByDesc('start_date')->limit(24)->get();
        $interested=$base()->whereIn('id',$interestedIds)->where('end_date','>',$now)->where('is_cancelled',false)->orderBy('start_date')->limit(24)->get();
        $favorites=$base()->whereIn('id',$favoriteIds)->where('end_date','>',$now)->where('is_cancelled',false)->orderBy('start_date')->limit(24)->get();
        $passes=EventPass::where('user_id',$user->id)->whereHas('event',fn($q)=>$q->where('app_id',$appId))->with(['ticket:id,event_id,name,type,ticket_type','event:id,title,slug,start_date,end_date,city,uf,image'])->latest()->limit(50)->get();
        return response()->json([
            'profile'=>['id'=>$user->id,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'user_name'=>$user->user_name,'avatar'=>$user->avatar,'background'=>$user->background,'city'=>$user->city,'uf'=>$user->uf,'about'=>$user->about,'favorite_artist'=>$user->favorite_artist,'favorite_genre'=>$user->favorite_genre],
            'stats'=>['tickets'=>$passes->count(),'upcoming_with_ticket'=>$ticketUpcoming->count(),'interested'=>$interested->count(),'favorites'=>$favorites->count(),'following_artists'=>DB::table('follows')->where(['app_id'=>$appId,'user_id'=>$user->id,'target_type'=>'artist'])->count(),'following_organizations'=>DB::table('follows')->where(['app_id'=>$appId,'user_id'=>$user->id,'target_type'=>'production'])->count(),'posts'=>DB::table('event_posts')->where(['app_id'=>$appId,'user_id'=>$user->id,'status'=>'published'])->count()],
            'upcoming_with_ticket'=>$ticketUpcoming,'past_with_ticket'=>$ticketPast,'interested_events'=>$interested,'favorite_events'=>$favorites,'passes'=>$passes,
        ]);
    }
}
