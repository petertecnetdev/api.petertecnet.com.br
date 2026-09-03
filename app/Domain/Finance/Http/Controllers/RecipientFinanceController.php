<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\FinancialIdentityService;
use App\Services\RecipientSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecipientFinanceController extends Controller
{
    public function __construct(
        private readonly FinancialIdentityService $identity,
        private readonly RecipientSettlementService $settlement,
    ) {}

    public function overview(Request $request)
    {
        return response()->json($this->settlement->overview($request->user()));
    }

    public function saveIdentity(Request $request)
    {
        $data = $request->validate([
            'legal_name' => 'nullable|string|min:5|max:190',
            'document_type' => 'nullable|string|in:CPF',
            'document_number' => 'nullable|string|max:30',
            'birthdate' => 'nullable|date|before:-18 years',
        ]);

        $before = DB::table('financial_beneficiaries')->where('user_id', $request->user()->id)->first();
        $this->identity->saveProfile($request->user(), $data);
        $after = DB::table('financial_beneficiaries')->where('user_id', $request->user()->id)->first();
        $changed = $before && $after && $this->identityChanged($before, $after);
        if ($changed) {
            DB::transaction(function () use ($after) {
                DB::table('financial_beneficiaries')->where('id', $after->id)->update([
                    'status' => 'pending', 'verification_level' => 'profile', 'verified_at' => null, 'updated_at' => now(),
                ]);
                DB::table('financial_payout_destinations')->where('beneficiary_id', $after->id)->update([
                    'status' => 'identity_changed', 'verified_at' => null, 'cooling_until' => null, 'changed_at' => now(), 'updated_at' => now(),
                ]);
            });
        }

        return response()->json([
            'message' => $changed
                ? 'Dados alterados. Por segurança, refaça a verificação e confirme novamente sua chave Pix.'
                : 'Dados de identidade salvos.',
            ...$this->settlement->overview($request->user()->fresh()),
        ]);
    }

    public function uploadDocument(Request $request)
    {
        $data = $request->validate([
            'front' => 'required|file|mimes:jpg,jpeg,png,webp|max:8192',
            'back' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'consent' => 'required|accepted',
        ]);
        $this->identity->uploadDocuments($request->user(), $request->file('front'), $request->file('back'), (bool) $data['consent']);
        return response()->json(['message' => 'Documento recebido. Agora faça a prova de vida.', ...$this->settlement->overview($request->user())], 201);
    }

    public function startLiveness(Request $request)
    {
        try {
            return response()->json($this->identity->startLiveness($request->user()));
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function completeLiveness(Request $request)
    {
        $data = $request->validate(['session_id' => 'required|string|min:20|max:200']);
        try {
            $identity = $this->identity->completeLiveness($request->user(), $data['session_id']);
            $verified = data_get($identity, 'beneficiary.status') === 'verified';
            return response()->json([
                'message' => $verified ? 'Identidade confirmada com sucesso.' : 'A verificação precisa ser refeita ou analisada.',
                ...$this->settlement->overview($request->user()->fresh()),
            ], $verified ? 200 : 422);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Não foi possível concluir a prova de vida. Tente novamente.'], 502);
        }
    }

    public function savePix(Request $request)
    {
        $data = $request->validate([
            'pix_key_type' => 'required|string|in:CPF,CNPJ,EMAIL,PHONE,EVP',
            'pix_key' => 'required|string|min:3|max:190',
        ]);
        try {
            $overview = $this->settlement->savePixDestination($request->user(), $data['pix_key_type'], $data['pix_key']);
            return response()->json([
                'message' => data_get($overview, 'destination.status') === 'cooling'
                    ? 'Nova chave Pix verificada. Por segurança, os repasses ficarão bloqueados durante o período indicado.'
                    : 'Chave Pix verificada e ativada para recebimentos.',
                ...$overview,
            ]);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function requestPayout(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:999999999.99']);
        try {
            return response()->json($this->settlement->requestPayout($request->user(), (float) $data['amount']), 201);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    private function identityChanged(object $before, object $after): bool
    {
        return (string) $before->document_number_hash !== (string) $after->document_number_hash
            || mb_strtolower(trim((string) $before->legal_name)) !== mb_strtolower(trim((string) $after->legal_name))
            || (string) ($before->birthdate ?? '') !== (string) ($after->birthdate ?? '');
    }
}
