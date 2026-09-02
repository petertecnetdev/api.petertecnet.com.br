<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class EventCommunityController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $event = $this->publicEventBySlug($slug); $appId = $this->context->id(); $user = $this->optionalUser($request);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);
        $posts = DB::table('event_posts as p')->join('users as u','u.id','=','p.user_id')
            ->where('p.app_id',$appId)->where('p.event_id',$event->id)->whereNull('p.parent_id')->where('p.status','published')
            ->select(['p.id','p.event_id','p.user_id','p.body','p.is_pinned','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(fn($q)=>$q->from('event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id','p.id')->where('l.app_id',$appId),'likes_count')
            ->selectSub(fn($q)=>$q->from('event_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id','p.id')->where('r.status','published'),'comments_count')
            ->orderByDesc('p.is_pinned')->orderByDesc('p.created_at')->paginate($perPage);

        $ids=collect($posts->items())->pluck('id')->filter()->values();
        $replies=$ids->isEmpty()?collect():DB::table('event_posts as p')->join('users as u','u.id','=','p.user_id')
            ->where('p.app_id',$appId)->where('p.event_id',$event->id)->whereIn('p.parent_id',$ids)->where('p.status','published')
            ->select(['p.id','p.parent_id','p.user_id','p.body','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(fn($q)=>$q->from('event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id','p.id')->where('l.app_id',$appId),'likes_count')
            ->orderBy('p.created_at')->get()->groupBy('parent_id');
        $liked=collect();
        if($user){$all=$ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();if($all->isNotEmpty())$liked=DB::table('event_post_likes')->where('app_id',$appId)->where('user_id',$user->id)->whereIn('post_id',$all)->pluck('post_id');}
        $posts->setCollection(collect($posts->items())->map(function($post)use($replies,$liked,$user){$post->is_liked=$user?$liked->contains($post->id):false;$post->replies=collect($replies->get($post->id,[]))->map(function($reply)use($liked,$user){$reply->is_liked=$user?$liked->contains($reply->id):false;return$reply;})->values();return$post;}));
        $rating=DB::table('event_ratings')->where('app_id',$appId)->where('event_id',$event->id)->selectRaw('ROUND(AVG(rating),1) average, COUNT(*) total')->first();
        $mine=$user?DB::table('event_ratings')->where(['app_id'=>$appId,'event_id'=>$event->id,'user_id'=>$user->id])->value('rating'):null;
        return response()->json(['posts'=>$posts,'rating'=>['average'=>$rating?->average?(float)$rating->average:0,'total'=>(int)($rating?->total??0),'mine'=>$mine?(int)$mine:null]]);
    }

    public function createPost(Request $request,int $eventId)
    {
        $user=$request->user();$event=$this->publicEventById($eventId);$appId=$this->context->id();$data=$request->validate(['body'=>'required|string|min:2|max:3000','parent_id'=>'nullable|integer|min:1']);$parent=null;
        if($parentId=$data['parent_id']??null){$parent=DB::table('event_posts')->where('id',$parentId)->where('app_id',$appId)->where('event_id',$event->id)->whereNull('parent_id')->where('status','published')->first();abort_unless($parent,422,'A publicação que você tentou responder não está mais disponível.');}
        $id=DB::table('event_posts')->insertGetId(['app_id'=>$appId,'event_id'=>$event->id,'user_id'=>$user->id,'parent_id'=>$data['parent_id']??null,'body'=>trim($data['body']),'status'=>'published','is_pinned'=>false,'created_at'=>now(),'updated_at'=>now()]);
        $this->notifyCommunityActivity($event,$user,$id,$parent);return response()->json(['message'=>$parent?'Comentário publicado.':'Publicação adicionada ao evento.','post_id'=>$id],201);
    }

    public function deletePost(Request $request,int $postId)
    {
        $user=$request->user();$appId=$this->context->id();$post=DB::table('event_posts')->where('app_id',$appId)->where('id',$postId)->first();abort_unless($post,404,'Publicação não encontrada.');
        $event=Event::with('production')->where('app_id',$appId)->find($post->event_id);$can=(int)$post->user_id===(int)$user->id||$user->hasProfile('Administrador')||($event?->production&&(int)$event->production->user_id===(int)$user->id);abort_unless($can,403,'Você não tem permissão para remover esta publicação.');
        DB::table('event_posts')->where('app_id',$appId)->where(fn($q)=>$q->where('id',$postId)->orWhere('parent_id',$postId))->update(['status'=>'hidden','updated_at'=>now()]);return response()->json(['message'=>'Publicação removida.']);
    }

    public function like(Request $request,int $postId)
    {
        $user=$request->user();$post=$this->publishedPost($postId);$appId=$this->context->id();$already=DB::table('event_post_likes')->where(['app_id'=>$appId,'post_id'=>$post->id,'user_id'=>$user->id])->exists();
        DB::table('event_post_likes')->updateOrInsert(['app_id'=>$appId,'post_id'=>$post->id,'user_id'=>$user->id],['created_at'=>now(),'updated_at'=>now()]);
        if(!$already&&(int)$post->user_id!==(int)$user->id){$event=Event::where('app_id',$appId)->find($post->event_id);if($event)$this->safeNotify($appId,(int)$post->user_id,['type'=>'comment_like','title'=>'Curtiram sua publicação','message'=>(trim((string)$user->first_name)?:'Alguém').' curtiu o que você publicou em '.$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/event/'.$event->slug.'#comunidade','data'=>['event_id'=>$event->id,'post_id'=>$post->id,'actor_id'=>$user->id]]);}
        return response()->json(['message'=>'Publicação curtida.','liked'=>true]);
    }

    public function unlike(Request $request,int $postId){DB::table('event_post_likes')->where(['app_id'=>$this->context->id(),'post_id'=>$postId,'user_id'=>$request->user()->id])->delete();return response()->json(['message'=>'Curtida removida.','liked'=>false]);}
    public function rate(Request $request,int $eventId){$event=$this->publicEventById($eventId);$data=$request->validate(['rating'=>'required|integer|min:1|max:5']);$verified=EventPass::where('event_id',$event->id)->where('user_id',$request->user()->id)->exists();DB::table('event_ratings')->updateOrInsert(['app_id'=>$this->context->id(),'event_id'=>$event->id,'user_id'=>$request->user()->id],['rating'=>(int)$data['rating'],'verified_attendee'=>$verified,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['message'=>'Sua avaliação foi registrada.','rating'=>(int)$data['rating'],'verified_attendee'=>$verified]);}
    public function report(Request $request,int $eventId){$event=$this->publicEventById($eventId);$data=$request->validate(['reason'=>'required|in:fraud,misleading,inappropriate,safety,cancelled,illegal,hate,harassment,spam,copyright,other','details'=>'nullable|string|max:3000']);DB::table('event_reports')->updateOrInsert(['app_id'=>$this->context->id(),'event_id'=>$event->id,'user_id'=>$request->user()->id],['reason'=>$data['reason'],'details'=>trim((string)($data['details']??''))?:null,'status'=>'open','reviewed_by'=>null,'reviewed_at'=>null,'moderation_note'=>null,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['message'=>'Denúncia enviada para revisão.']);}

    private function notifyCommunityActivity(Event $event,User $actor,int $postId,?object $parent):void
    {
        $appId=$this->context->id();$parentAuthor=$parent?(int)$parent->user_id:null;$attendees=EventPass::where('event_id',$event->id)->whereNotNull('user_id')->whereNotIn('status',['cancelled','refunded','charged_back'])->distinct()->pluck('user_id')->map(fn($id)=>(int)$id)->reject(fn($id)=>$id===(int)$actor->id||($parentAuthor&&$id===$parentAuthor))->values();
        $name=trim((string)$actor->first_name)?:'Alguém';$payload=['type'=>$parent?'event_reply':'event_comment','title'=>$parent?'Nova resposta no evento':'Novo comentário no evento','message'=>$name.($parent?' respondeu uma conversa em ':' publicou na conversa de ').$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/event/'.$event->slug.'#comunidade','data'=>['event_id'=>$event->id,'post_id'=>$postId,'actor_id'=>$actor->id]];
        try{$this->notifications->sendToUsers($appId,$attendees,$payload,(int)$actor->id);}catch(Throwable $e){report($e);}if($parentAuthor&&$parentAuthor!==(int)$actor->id)$this->safeNotify($appId,$parentAuthor,$payload);
    }
    private function safeNotify(int $appId,int $userId,array $payload):void{try{$this->notifications->sendToUser($appId,$userId,$payload);}catch(Throwable $e){report($e);}}
    private function publishedPost(int $id):object{$post=DB::table('event_posts')->where('app_id',$this->context->id())->where('id',$id)->where('status','published')->first();abort_unless($post,404,'Publicação não encontrada.');return$post;}
    private function publicEventBySlug(string $slug):Event{return Event::where('app_id',$this->context->id())->where('slug',$slug)->where('is_published',true)->where('is_cancelled',false)->where(fn($q)=>$q->where('is_private',false)->orWhereNull('is_private'))->firstOrFail();}
    private function publicEventById(int $id):Event{return Event::where('app_id',$this->context->id())->whereKey($id)->where('is_published',true)->where('is_cancelled',false)->where(fn($q)=>$q->where('is_private',false)->orWhereNull('is_private'))->firstOrFail();}
    private function optionalUser(Request $request):?User{$token=$request->bearerToken();if(!$token)return null;try{$user=JWTAuth::setToken($token)->authenticate();return$user instanceof User?$user:null;}catch(Throwable){return null;}}
}
