<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoService
{
    private string $baseUrl = 'https://api.mercadopago.com';

    public function authorizationUrl(string $state): string
    {
        return 'https://auth.mercadopago.com.br/authorization?' . http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        return $this->oauthToken([
            'client_id' => $this->clientId(), 'client_secret' => $this->clientSecret(),
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri(),
        ]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->oauthToken(['client_id'=>$this->clientId(),'client_secret'=>$this->clientSecret(),'grant_type'=>'refresh_token','refresh_token'=>$refreshToken]);
    }

    public function createPayment(string $sellerAccessToken, array $payload, string $idempotencyKey): array
    {
        $response = $this->postPayment($sellerAccessToken, $payload, $idempotencyKey);
        if ($response->successful()) return $response->json();

        // When seller and platform are the same provider account there is no
        // marketplace split to perform, so retry without application_fee.
        if (array_key_exists('application_fee',$payload) && $this->isApplicationFeeNotAllowed($response->json()) && $this->sellerIsPlatformAccount($sellerAccessToken)) {
            unset($payload['application_fee']); data_set($payload,'metadata.settlement_mode','same_account');
            $retry = $this->postPayment($sellerAccessToken,$payload,$idempotencyKey.'-same-account');
            if ($retry->successful()) { $result=$retry->json(); $result['_same_account']=true; return $result; }
            throw new RuntimeException('Mercado Pago recusou a criação do pagamento sem split para a conta própria da plataforma: '.$retry->body());
        }
        throw new RuntimeException('Mercado Pago recusou a criação do pagamento: '.$response->body());
    }

    /**
     * Checkout Pro keeps card data outside Peter Tecnet infrastructure while
     * exposing PIX, boleto and cards through one reusable hosted checkout.
     */
    public function createPreference(array $payload, string $idempotencyKey): array
    {
        $response = Http::acceptJson()
            ->withToken($this->platformAccessToken())
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->timeout(20)
            ->post($this->baseUrl.'/checkout/preferences', $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Mercado Pago recusou a criação do checkout: '.$response->body());
        }
        return $response->json();
    }

    public function getPayment(string $sellerAccessToken,string $paymentId):array
    {
        $response=Http::acceptJson()->withToken($sellerAccessToken)->timeout(20)->get($this->baseUrl.'/v1/payments/'.rawurlencode($paymentId));
        if(!$response->successful())throw new RuntimeException('Não foi possível consultar o pagamento no Mercado Pago.');return$response->json();
    }

    public function platformAccessToken(): string
    {
        $value = trim((string) config('services.mercadopago.access_token'));
        if ($value === '') throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        return $value;
    }

    public function validateWebhookSignature(?string $xSignature,?string $xRequestId,?string $dataId):bool
    {
        $secret=trim((string)config('services.mercadopago.webhook_secret'));if($secret===''||!$xSignature||!$xRequestId||!$dataId)return false;$parts=[];
        foreach(explode(',',$xSignature)as$part){[$key,$value]=array_pad(explode('=',trim($part),2),2,null);if($key&&$value)$parts[$key]=$value;}if(empty($parts['ts'])||empty($parts['v1']))return false;
        $manifest='id:'.strtolower($dataId).';request-id:'.$xRequestId.';ts:'.$parts['ts'].';';return hash_equals(hash_hmac('sha256',$manifest,$secret),$parts['v1']);
    }

    private function postPayment(string $accessToken,array $payload,string $idempotencyKey){return Http::acceptJson()->withToken($accessToken)->withHeaders(['X-Idempotency-Key'=>$idempotencyKey])->timeout(20)->post($this->baseUrl.'/v1/payments',$payload);}
    private function isApplicationFeeNotAllowed(array $body):bool{foreach(($body['cause']??[])as$cause)if((int)($cause['code']??0)===2059)return true;return str_contains(strtolower((string)($body['message']??'')),'cannot use application_fee');}
    private function sellerIsPlatformAccount(string $sellerAccessToken):bool{$platform=trim((string)config('services.mercadopago.access_token'));if($platform==='')return false;$seller=$this->currentUser($sellerAccessToken);$platformUser=$this->currentUser($platform);$sellerId=(string)($seller['id']??'');$platformId=(string)($platformUser['id']??'');return$sellerId!==''&&$platformId!==''&&hash_equals($platformId,$sellerId);}
    private function currentUser(string $accessToken):array{$response=Http::acceptJson()->withToken($accessToken)->timeout(20)->get($this->baseUrl.'/users/me');return$response->successful()?$response->json():[];}
    private function oauthToken(array $form):array{$response=Http::asForm()->acceptJson()->timeout(20)->post($this->baseUrl.'/oauth/token',$form);if(!$response->successful())throw new RuntimeException('Não foi possível concluir a autorização do Mercado Pago: '.$response->body());return$response->json();}
    private function clientId():string{$value=trim((string)config('services.mercadopago.client_id'));if($value==='')throw new RuntimeException('MERCADOPAGO_CLIENT_ID não configurado.');return$value;}
    private function clientSecret():string{$value=trim((string)config('services.mercadopago.client_secret'));if($value==='')throw new RuntimeException('MERCADOPAGO_CLIENT_SECRET não configurado.');return$value;}
    private function redirectUri():string{$value=trim((string)config('services.mercadopago.redirect_uri'));if($value==='')throw new RuntimeException('MERCADOPAGO_REDIRECT_URI não configurado.');return$value;}
}
