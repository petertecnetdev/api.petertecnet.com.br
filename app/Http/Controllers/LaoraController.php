<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LaoraController extends Controller
{
    private const APP_SLUG = 'laora';

    public function profile(Request $request)
    {
        $user = $request->user();
        return response()->json(['data' => $this->profilePayload($user->id, true)]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'display_name' => ['nullable','string','max:120'],
            'birth_date' => ['nullable','date','before_or_equal:'.now()->subYears(18)->toDateString()],
            'gender' => ['nullable', Rule::in(['man','woman','nonbinary','other'])],
            'interested_in' => ['nullable','array','max:4'],
            'interested_in.*' => [Rule::in(['man','woman','nonbinary','other'])],
            'preferred_genders' => ['nullable','array','max:4'],
            'preferred_genders.*' => [Rule::in(['man','woman','nonbinary','other'])],
            'bio' => ['nullable','string','max:1200'],
            'city' => ['nullable','string','max:100'],
            'uf' => ['nullable','string','size:2'],
            'latitude' => ['nullable','numeric','between:-90,90'],
            'longitude' => ['nullable','numeric','between:-180,180'],
            'max_distance_km' => ['nullable','integer','between:1,500'],
            'min_age' => ['nullable','integer','between:18,99'],
            'max_age' => ['nullable','integer','between:18,99'],
            'is_discoverable' => ['nullable','boolean'],
        ]);
        if (isset($data['min_age'], $data['max_age'])) abort_if($data['min_age'] > $data['max_age'], 422, 'A idade mínima não pode ser maior que a máxima.');
        if (isset($data['uf'])) $data['uf'] = strtoupper($data['uf']);
        if (array_key_exists('interested_in', $data) && !array_key_exists('preferred_genders', $data)) $data['preferred_genders'] = $data['interested_in'];
        unset($data['interested_in']);
        foreach (['preferred_genders'] as $json) if (array_key_exists($json, $data)) $data[$json] = json_encode(array_values(array_unique($data[$json] ?? [])));
        $data['updated_at'] = now();
        DB::table('laora_profiles')->updateOrInsert(['user_id'=>$user->id], $data + ['created_at'=>now()]);
        return response()->json(['data'=>$this->profilePayload($user->id,true)]);
    }

    public function uploadPhoto(Request $request)
    {
        $user=$request->user();
        $data=$request->validate(['photo'=>['required','image','max:8192'],'is_primary'=>['nullable','boolean']]);
        $count=DB::table('laora_photos')->where('user_id',$user->id)->count();
        abort_if($count>=9,422,'Você pode manter no máximo 9 fotos.');
        $path=$request->file('photo')->store('laora/photos','public');
        $makePrimary=($data['is_primary']??false)||$count===0;
        if($makePrimary)DB::table('laora_photos')->where('user_id',$user->id)->update(['is_primary'=>false]);
        $id=DB::table('laora_photos')->insertGetId(['user_id'=>$user->id,'path'=>$path,'is_primary'=>$makePrimary,'position'=>$count,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('laora_photos')->find($id)],201);
    }

    public function deletePhoto(Request $request,int $photoId)
    {
        $photo=DB::table('laora_photos')->where('id',$photoId)->where('user_id',$request->user()->id)->first();
        abort_unless($photo,404,'Foto não encontrada.');
        Storage::disk('public')->delete($photo->path);
        DB::table('laora_photos')->where('id',$photoId)->delete();
        if($photo->is_primary){$next=DB::table('laora_photos')->where('user_id',$request->user()->id)->orderBy('position')->first();if($next)DB::table('laora_photos')->where('id',$next->id)->update(['is_primary'=>true]);}
        return response()->json(['message'=>'Foto removida.']);
    }

    public function discover(Request $request)
    {
        $user=$request->user();$this->assertVerified($user);$this->assertActive($user);
        $profile=DB::table('laora_profiles')->where('user_id',$user->id)->first();
        abort_unless($profile,409,'Complete seu perfil antes de descobrir pessoas.');
        abort_unless((bool)$profile->is_discoverable,409,'Ative a descoberta no seu perfil.');
        $preferred=$this->decodeJson($profile->preferred_genders);
        $query=DB::table('laora_profiles as lp')->join('users as u','u.id','=','lp.user_id')->where('lp.user_id','!=',$user->id)->where('lp.is_discoverable',true)->whereNotNull('u.email_verified_at');
        if($preferred)$query->whereIn('lp.gender',$preferred);
        $candidateRows=$query->orderByDesc('lp.updated_at')->limit(300)->get();
        $already=DB::table('laora_swipes')->where('actor_user_id',$user->id)->pluck('target_user_id')->all();
        $blocked=DB::table('laora_blocks')->where('blocker_user_id',$user->id)->pluck('blocked_user_id')->merge(DB::table('laora_blocks')->where('blocked_user_id',$user->id)->pluck('blocker_user_id'))->unique()->all();
        $candidateRows=$candidateRows->reject(fn($row)=>in_array($row->user_id,$already,true)||in_array($row->user_id,$blocked,true));
        $myGender=$profile->gender;
        $candidateRows=$candidateRows->filter(function($candidate)use($myGender,$profile){$candidatePreferred=$this->decodeJson($candidate->preferred_genders);if($candidatePreferred&&$myGender&&!in_array($myGender,$candidatePreferred,true))return false;if(!$profile->birth_date||!$candidate->birth_date)return true;$age=now()->diffInYears($candidate->birth_date);return $age>=$profile->min_age&&$age<=$profile->max_age;});
        $candidateRows=$candidateRows->map(function($candidate)use($profile){$distance=$this->distanceKm($profile,$candidate);if($distance!==null&&$distance>(int)$profile->max_distance_km)return null;$payload=$this->profilePayload($candidate->user_id,false);$payload['distance_km']=$distance===null?null:round($distance,1);return $payload;})->filter()->values();
        return response()->json(['data'=>$candidateRows]);
    }

    public function matches(Request $request)
    {
        $user=$request->user();$this->assertVerified($user);$this->assertActive($user);
        $rows=DB::table('laora_matches')->where(fn($q)=>$q->where('user_one_id',$user->id)->orWhere('user_two_id',$user->id))->where('status','active')->orderByDesc('matched_at')->get()->map(function($match)use($user){$other=$match->user_one_id===$user->id?$match->user_two_id:$match->user_one_id;return['id'=>$match->id,'matched_at'=>$match->matched_at,'person'=>$this->profilePayload($other,false),'last_message'=>DB::table('laora_messages')->where('match_id',$match->id)->latest('id')->first()];});
        return response()->json(['data'=>$rows]);
    }

    public function messages(Request $request,int $matchId)
    {
        $user=$request->user();$this->assertVerified($user);$this->assertActive($user);$this->ownedMatch($matchId,$user->id,true);
        $messages=DB::table('laora_messages')->where('match_id',$matchId)->orderBy('id')->limit(500)->get();
        DB::table('laora_messages')->where('match_id',$matchId)->where('sender_user_id','!=',$user->id)->whereNull('read_at')->update(['read_at'=>now()]);
        return response()->json(['data'=>$messages]);
    }

    public function sendMessage(Request $request,int $matchId)
    {
        $user=$request->user();$this->assertVerified($user);$this->assertActive($user);$match=$this->ownedMatch($matchId,$user->id,true);$other=$match->user_one_id===$user->id?$match->user_two_id:$match->user_one_id;$this->assertNotBlocked($user->id,$other);
        $data=$request->validate(['body'=>['required','string','max:4000']]);
        $id=DB::table('laora_messages')->insertGetId(['match_id'=>$matchId,'sender_user_id'=>$user->id,'body'=>trim($data['body']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('laora_matches')->where('id',$matchId)->update(['updated_at'=>now()]);
        return response()->json(['data'=>DB::table('laora_messages')->find($id)],201);
    }

    public function swipe(Request $request)
    {
        $user=$request->user();$this->assertVerified($user);$this->assertActive($user);
        $data=$request->validate(['target_user_id'=>['required','integer','different:actor_user_id','exists:users,id'],'direction'=>['required',Rule::in(['like','pass'])]]);
        $target=(int)$data['target_user_id'];$this->assertNotBlocked($user->id,$target);abort_unless(DB::table('laora_profiles')->where('user_id',$target)->where('is_discoverable',true)->exists(),404,'Perfil indisponível.');
        DB::table('laora_swipes')->updateOrInsert(['actor_user_id'=>$user->id,'target_user_id'=>$target],['direction'=>$data['direction'],'updated_at'=>now(),'created_at'=>now()]);
        $matched=false;$matchId=null;
        if($data['direction']==='like'&&DB::table('laora_swipes')->where('actor_user_id',$target)->where('target_user_id',$user->id)->where('direction','like')->exists()){
            [$one,$two]=$this->orderedPair($user->id,$target);$existing=DB::table('laora_matches')->where('user_one_id',$one)->where('user_two_id',$two)->first();if($existing){DB::table('laora_matches')->where('id',$existing->id)->update(['status'=>'active','matched_at'=>$existing->matched_at?:now(),'unmatched_at'=>null,'updated_at'=>now()]);$matchId=$existing->id;}else{$matchId=DB::table('laora_matches')->insertGetId(['user_one_id'=>$one,'user_two_id'=>$two,'status'=>'active','matched_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}$matched=true;
        }
        return response()->json(['data'=>['matched'=>$matched,'match_id'=>$matchId]]);
    }

    public function unmatch(Request $request,int $matchId)
    {
        $match=$this->ownedMatch($matchId,$request->user()->id,true);DB::table('laora_matches')->where('id',$match->id)->update(['status'=>'unmatched','unmatched_at'=>now(),'updated_at'=>now()]);return response()->json(['message'=>'Match desfeito.']);
    }

    public function block(Request $request,int $targetUserId)
    {
        $user=$request->user();abort_if($user->id===$targetUserId,422,'Você não pode bloquear sua própria conta.');abort_unless(User::query()->whereKey($targetUserId)->exists(),404,'Usuário não encontrado.');
        DB::table('laora_blocks')->updateOrInsert(['blocker_user_id'=>$user->id,'blocked_user_id'=>$targetUserId],['created_at'=>now(),'updated_at'=>now()]);[$one,$two]=$this->orderedPair($user->id,$targetUserId);DB::table('laora_matches')->where('user_one_id',$one)->where('user_two_id',$two)->update(['status'=>'blocked','updated_at'=>now()]);return response()->json(['message'=>'Usuário bloqueado.']);
    }

    public function unblock(Request $request,int $targetUserId)
    {
        DB::table('laora_blocks')->where('blocker_user_id',$request->user()->id)->where('blocked_user_id',$targetUserId)->delete();return response()->json(['message'=>'Bloqueio removido.']);
    }

    public function report(Request $request)
    {
        $user=$request->user();$data=$request->validate(['target_user_id'=>['required','integer','exists:users,id'],'reason'=>['required','string','max:100'],'details'=>['nullable','string','max:2000']]);abort_if($user->id===(int)$data['target_user_id'],422,'Você não pode denunciar sua própria conta.');
        $id=DB::table('laora_reports')->insertGetId(['reporter_user_id'=>$user->id,'target_user_id'=>$data['target_user_id'],'reason'=>$data['reason'],'details'=>$data['details']??null,'status'=>'open','created_at'=>now(),'updated_at'=>now()]);return response()->json(['data'=>DB::table('laora_reports')->find($id)],201);
    }

    private function profilePayload(int $userId,bool $private): array
    {
        $user=User::query()->findOrFail($userId);$profile=DB::table('laora_profiles')->where('user_id',$userId)->first();
        if(!$profile){return['user'=>['id'=>$user->id,'name'=>trim($user->first_name.' '.$user->last_name),'email'=>$private?$user->email:null],'profile'=>null,'photos'=>[]];}
        $profileArray=(array)$profile;foreach(['preferred_genders'] as $json)$profileArray[$json]=$this->decodeJson($profileArray[$json]??null);$photos=DB::table('laora_photos')->where('user_id',$userId)->orderByDesc('is_primary')->orderBy('position')->get()->map(fn($photo)=>['id'=>$photo->id,'url'=>Storage::disk('public')->url($photo->path),'is_primary'=>(bool)$photo->is_primary,'position'=>$photo->position]);
        return['user'=>['id'=>$user->id,'name'=>trim($user->first_name.' '.$user->last_name),'email'=>$private?$user->email:null],'profile'=>$profileArray,'photos'=>$photos];
    }

    private function ownedMatch(int $matchId, int $userId, bool $requireActive = false): object
    {
        $match = DB::table('laora_matches')->where('id', $matchId)
            ->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))->first();
        abort_unless($match, 404, 'Match não encontrado.');
        if ($requireActive) abort_unless($match->status === 'active', 409, 'Este match não está ativo.');
        return $match;
    }

    private function assertNotBlocked(int $one, int $two): void
    {
        $blocked = DB::table('laora_blocks')->where(fn ($q) => $q->where('blocker_user_id', $one)->where('blocked_user_id', $two))
            ->orWhere(fn ($q) => $q->where('blocker_user_id', $two)->where('blocked_user_id', $one))->exists();
        abort_if($blocked, 403, 'Interação indisponível entre estas contas.');
    }

    private function assertVerified(User $user): void
    {
        abort_unless($user->email_verified_at, 403, 'Verifique seu e-mail para usar descoberta, matches e mensagens.');
    }

    private function assertActive(User $user): void
    {
        $action = DB::table('laora_moderation_actions')->where('target_user_id', $user->id)
            ->whereIn('action', ['suspend', 'ban'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->first();

        if ($action) {
            abort(403, $action->action === 'ban'
                ? 'Sua conta está impedida de usar o Laora.'
                : 'Seu acesso ao Laora está temporariamente suspenso.');
        }
    }

    private function orderedPair(int $one,int $two):array{return$one<$two?[$one,$two]:[$two,$one];}
    private function decodeJson($value):array{if(is_array($value))return$value;$decoded=json_decode((string)$value,true);return is_array($decoded)?$decoded:[];}
    private function distanceKm(object $one,object $two):?float{if($one->latitude===null||$one->longitude===null||$two->latitude===null||$two->longitude===null)return null;$earth=6371;$latFrom=deg2rad((float)$one->latitude);$latTo=deg2rad((float)$two->latitude);$latDelta=deg2rad((float)$two->latitude-(float)$one->latitude);$lonDelta=deg2rad((float)$two->longitude-(float)$one->longitude);$a=sin($latDelta/2)**2+cos($latFrom)*cos($latTo)*sin($lonDelta/2)**2;return$earth*2*atan2(sqrt($a),sqrt(1-$a));}
}
