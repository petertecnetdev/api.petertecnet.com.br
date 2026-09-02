<?php

namespace App\Http\Controllers;

use App\Models\Production;
use App\Services\OrganizerContractService;
use App\Support\ApplicationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrganizerContractController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(Request $request, int $productionId, OrganizerContractService $contracts)
    {
        $this->context->requireCapability('contracts');
        $production = $this->ownedProduction($request, $productionId);
        $text = $contracts->text($production);
        $acceptance = DB::table('cutinapp_producer_contract_acceptances')
            ->where('production_id', $production->id)->where('contract_version', $contracts->version())->first();
        return response()->json(['contract' => [
            'version'=>$contracts->version(),'hash'=>$contracts->hash($text),'text'=>$text,'accepted'=>(bool)$acceptance,
            'accepted_at'=>$acceptance?->accepted_at,'email_sent_at'=>$acceptance?->email_sent_at,
            'signer_name'=>$acceptance?->signer_name,'signer_document'=>$acceptance?->signer_document,
            'production'=>$production->only(['id','name','cnpj','slug']),
        ]]);
    }

    public function sign(Request $request, int $productionId, OrganizerContractService $contracts)
    {
        $this->context->requireCapability('contracts');
        $production=$this->ownedProduction($request,$productionId); $user=$request->user();
        $data=$request->validate(['signer_name'=>'required|string|min:3|max:255','signer_document'=>'required|string|min:5|max:32','signer_role'=>'nullable|string|max:120','accepted'=>'accepted']);
        $document=preg_replace('/\D+/','',$data['signer_document']);
        abort_if(strlen($document)<11||strlen($document)>14,422,'Informe um CPF ou CNPJ válido do signatário.');
        $text=$contracts->text($production); $hash=$contracts->hash($text);
        $acceptance=DB::transaction(function() use($request,$production,$user,$data,$document,$contracts,$text,$hash){
            $existing=DB::table('cutinapp_producer_contract_acceptances')->where('production_id',$production->id)->where('contract_version',$contracts->version())->first();
            if($existing)return $existing;
            $id=DB::table('cutinapp_producer_contract_acceptances')->insertGetId([
                'production_id'=>$production->id,'user_id'=>$user->id,'contract_version'=>$contracts->version(),'contract_hash'=>$hash,
                'contract_snapshot'=>$text,'signer_name'=>trim($data['signer_name']),'signer_document'=>$document,
                'signer_role'=>trim((string)($data['signer_role']??''))?:null,'ip_address'=>$request->ip(),
                'user_agent'=>Str::limit((string)$request->userAgent(),2000,''),'accepted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            return DB::table('cutinapp_producer_contract_acceptances')->find($id);
        });
        $emailSent=$acceptance->email_sent_at||$this->sendCopy($production,$user->email,$acceptance);
        return response()->json(['message'=>$emailSent?'Contrato assinado com sucesso. Enviamos uma cópia para o seu e-mail.':'Contrato assinado com sucesso. A cópia por e-mail ainda não pôde ser enviada.','contract'=>['version'=>$acceptance->contract_version,'hash'=>$acceptance->contract_hash,'accepted'=>true,'accepted_at'=>$acceptance->accepted_at,'email_sent_at'=>$emailSent?now()->toIso8601String():null,'signer_name'=>$acceptance->signer_name]]);
    }

    public function resend(Request $request,int $productionId,OrganizerContractService $contracts)
    {
        $this->context->requireCapability('contracts');
        $production=$this->ownedProduction($request,$productionId);
        $acceptance=DB::table('cutinapp_producer_contract_acceptances')->where('production_id',$production->id)->where('contract_version',$contracts->version())->firstOrFail();
        abort_unless($this->sendCopy($production,$request->user()->email,$acceptance),502,'Não foi possível enviar a cópia do contrato agora.');
        return response()->json(['message'=>'Cópia do contrato enviada para o seu e-mail.']);
    }

    public function pdf(Request $request,int $productionId,OrganizerContractService $contracts)
    {
        $this->context->requireCapability('contracts');
        $production=$this->ownedProduction($request,$productionId);
        $acceptance=DB::table('cutinapp_producer_contract_acceptances')->where('production_id',$production->id)->where('contract_version',$contracts->version())->firstOrFail();
        $html='<html><body><pre style="white-space:pre-wrap;font-family:DejaVu Sans;font-size:11px">'.e($acceptance->contract_snapshot).'</pre><hr><p>Assinado por: '.e($acceptance->signer_name).' em '.e((string)$acceptance->accepted_at).'</p></body></html>';
        return Pdf::loadHTML($html)->setPaper('a4')->download('contrato-organizador-'.$production->id.'.pdf');
    }

    private function sendCopy(Production $production,string $email,object $acceptance):bool
    {
        try {
            $html='<html><body><pre style="white-space:pre-wrap;font-family:DejaVu Sans;font-size:11px">'.e($acceptance->contract_snapshot).'</pre></body></html>';
            $pdf=Pdf::loadHTML($html)->output();
            $application=$this->context->application()->name ?: $this->context->slug();
            Mail::raw('Seu contrato de adesão da aplicação '.$application.' está anexado.',function($message)use($email,$production,$pdf,$application){$message->to($email)->subject('Contrato da organização '.$production->name.' — '.$application)->attachData($pdf,'contrato-'.$production->id.'.pdf',['mime'=>'application/pdf']);});
            DB::table('cutinapp_producer_contract_acceptances')->where('id',$acceptance->id)->update(['email_sent_at'=>now(),'updated_at'=>now()]);
            return true;
        } catch(\Throwable $e){report($e);return false;}
    }

    private function ownedProduction(Request $request,int $productionId):Production
    {
        $production=Production::query()->where('id',$productionId)->where('app_id',$this->context->id())->where('app_slug',$this->context->slug())->firstOrFail();
        $admin=method_exists($request->user(),'hasProfile')&&$request->user()->hasProfile('Administrador');
        abort_unless($admin||(int)$production->user_id===(int)$request->user()->id,403,'Você não pode representar esta organização.');
        return $production;
    }
}
