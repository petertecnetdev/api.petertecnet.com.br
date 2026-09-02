<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Contracts\PayoutProvider;
use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Services\AsaasWithdrawalAuthorizationService;
use App\Services\FinancialIdentityService;
use App\Services\FinancialPayoutService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FinancialController extends Controller
{
    public function __construct(
        private FinancialIdentityService $identity,
        private FinancialPayoutService $payouts,
        private PayoutProvider $payoutProvider,
        private AsaasWithdrawalAuthorizationService $withdrawalAuthorization,
        private ApplicationContext $context,
    ) {}

    public function overview(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        return response()->json($this->payouts->overview($organization, $request->user()));
    }

    public function saveIdentity(Request $request, int $organizationId)
    {
        $this->ownedOrganization($request, $organizationId);
        $data = $request->validate([
            'legal_name' => 'nullable|string|min:5|max:190',
            'document_type' => 'nullable|string|in:CPF',
            'document_number' => 'nullable|string|max:30',
            'birthdate' => 'nullable|date|before:-18 years',
        ]);
        $before = DB::table('financial_beneficiaries')->where('user_id', $request->user()->id)->first();
        $this->identity->saveProfile($request->user(), $data);
        $after = DB::table('financial_beneficiaries')->where('user_id', $request->user()->id)->first();
        $changed = $before && $after && $this->identityRecordChanged($before, $after);
        if ($changed) {
            DB::transaction(function () use ($after) {
                DB::table('financial_beneficiaries')->where('id', $after->id)->update(['status'=>'pending','verification_level'=>'profile','verified_at'=>null,'updated_at'=>now()]);
                DB::table('financial_payout_destinations')->where('beneficiary_id', $after->id)->update(['status'=>'identity_changed','verified_at'=>null,'cooling_until'=>null,'changed_at'=>now(),'updated_at'=>now()]);
            });
        }
        return response()->json(['message'=>$changed?'Dados de identidade alterados. Por segurança, refaça a verificação e confirme novamente sua chave Pix.':'Dados de identidade salvos.','identity'=>$this->identity->overview($request->user()->fresh())]);
    }

    public function uploadDocument(Request $request, int $organizationId)
    {
        $this->ownedOrganization($request, $organizationId);
        $data=$request->validate(['front'=>'required|file|mimes:jpg,jpeg,png,webp|max:8192','back'=>'nullable|file|mimes:jpg,jpeg,png,webp|max:8192','consent'=>'required|accepted']);
        return response()->json(['message'=>'Documento recebido. Agora faça a prova de vida.','identity'=>$this->identity->uploadDocuments($request->user(),$request->file('front'),$request->file('back'),(bool)$data['consent'])],201);
    }

    public function startLiveness(Request $request, int $organizationId)
    {
        $this->ownedOrganization($request, $organizationId);
        try{return response()->json($this->identity->startLiveness($request->user()));}catch(RuntimeException $e){report($e);return response()->json(['message'=>$e->getMessage()],503);}
    }

    public function completeLiveness(Request $request, int $organizationId)
    {
        $this->ownedOrganization($request, $organizationId);$data=$request->validate(['session_id'=>'required|string|min:20|max:200']);
        try{$identity=$this->identity->completeLiveness($request->user(),$data['session_id']);$verified=data_get($identity,'beneficiary.status')==='verified';return response()->json(['message'=>$verified?'Identidade confirmada com sucesso.':'A verificação precisa ser refeita ou analisada.','identity'=>$identity],$verified?200:422);}catch(RuntimeException $e){report($e);return response()->json(['message'=>'Não foi possível concluir a prova de vida. Tente novamente.'],502);}
    }

    public function savePix(Request $request, int $organizationId)
    {
        $organization=$this->ownedOrganization($request,$organizationId);$data=$request->validate(['pix_key_type'=>'required|string|in:CPF,CNPJ,EMAIL,PHONE,EVP','pix_key'=>'required|string|min:3|max:190']);
        try{$overview=$this->payouts->savePixDestination($organization,$request->user(),$data['pix_key_type'],$data['pix_key']);return response()->json(['message'=>data_get($overview,'destination.status')==='cooling'?'Nova chave Pix verificada. Por segurança, os repasses ficarão bloqueados durante o período indicado.':'Chave Pix verificada e ativada para recebimentos.',...$overview]);}catch(RuntimeException $e){report($e);return response()->json(['message'=>$e->getMessage()],422);}
    }

    public function requestPayout(Request $request, int $organizationId)
    {
        $organization=$this->ownedOrganization($request,$organizationId);$data=$request->validate(['amount'=>'required|numeric|min:0.01|max:999999999.99']);$amount=round((float)$data['amount'],2);$overview=$this->payouts->overview($organization,$request->user());$eligible=(bool)($overview['ready_for_payout']??false)&&$amount<=(float)data_get($overview,'balance.available',0)+0.00001;
        if($eligible){if(!$this->payoutProvider->isConfigured())return response()->json(['message'=>'O serviço de repasses Pix ainda não está configurado para operação.'],503);try{if($this->payoutProvider->availableBalance()+0.00001<$amount)return response()->json(['message'=>'O repasse está temporariamente aguardando liquidação operacional. Tente novamente mais tarde.'],503);}catch(RuntimeException $e){report($e);return response()->json(['message'=>'Não foi possível confirmar a disponibilidade operacional do repasse agora. Tente novamente.'],503);}}
        try{return response()->json($this->payouts->requestPayout($organization,$request->user(),$amount),201);}catch(RuntimeException $e){report($e);return response()->json(['message'=>$e->getMessage()],502);}
    }

    public function providerWebhook(Request $request)
    {
        $this->assertProviderToken($request,'webhook_token');try{$this->payouts->processWebhook($request->all());}catch(Throwable $e){report($e);return response()->json(['ok'=>false],500);}return response()->json(['ok'=>true]);
    }

    public function withdrawalValidation(Request $request)
    {
        $this->assertProviderToken($request,'withdrawal_auth_token');return response()->json($this->withdrawalAuthorization->authorize($request->all()));
    }

    private function assertProviderToken(Request $request,string $configKey):void{$expected=trim((string)config('services.asaas.'.$configKey));$provided=trim((string)$request->header('asaas-access-token'));abort_unless($expected!==''&&$provided!==''&&hash_equals($expected,$provided),401,'Webhook não autenticado.');}
    private function identityRecordChanged(object $before,object $after):bool{return(string)$before->document_number_hash!==(string)$after->document_number_hash||mb_strtolower(trim((string)$before->legal_name))!==mb_strtolower(trim((string)$after->legal_name))||(string)($before->birthdate??'')!==(string)($after->birthdate??'');}
    private function ownedOrganization(Request $request,int $organizationId):Production{$organization=Production::query()->where('app_id',$this->context->id())->findOrFail($organizationId);$user=$request->user();$admin=$user&&method_exists($user,'hasProfile')&&$user->hasProfile('Administrador');abort_unless($user&&($admin||(int)$organization->user_id===(int)$user->id),403);return$organization;}
}
