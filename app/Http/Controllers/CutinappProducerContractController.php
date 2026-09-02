<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Production;
use App\Services\CutinappProducerContractService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CutinappProducerContractController extends Controller
{
    private const APP = 'cutinapp';

    public function show(Request $request, int $productionId, CutinappProducerContractService $contracts)
    {
        $production = $this->ownedProduction($request, $productionId);
        $text = $contracts->text($production);
        $acceptance = DB::table('cutinapp_producer_contract_acceptances')
            ->where('production_id', $production->id)
            ->where('contract_version', $contracts->version())
            ->first();

        return response()->json([
            'contract' => [
                'version' => $contracts->version(),
                'hash' => $contracts->hash($text),
                'text' => $text,
                'accepted' => (bool) $acceptance,
                'accepted_at' => $acceptance?->accepted_at,
                'signer_name' => $acceptance?->signer_name,
                'signer_document' => $acceptance?->signer_document,
                'production' => $production->only(['id','name','cnpj','slug']),
            ],
        ]);
    }

    public function sign(Request $request, int $productionId, CutinappProducerContractService $contracts)
    {
        $production = $this->ownedProduction($request, $productionId);
        $user = $request->user();
        $data = $request->validate([
            'signer_name' => 'required|string|min:3|max:255',
            'signer_document' => 'required|string|min:5|max:32',
            'signer_role' => 'nullable|string|max:120',
            'accepted' => 'accepted',
        ]);

        $document = preg_replace('/\D+/', '', $data['signer_document']);
        abort_if(strlen($document) < 11 || strlen($document) > 14, 422, 'Informe um CPF ou CNPJ válido do signatário.');

        $text = $contracts->text($production);
        $hash = $contracts->hash($text);

        $acceptance = DB::transaction(function () use ($request, $production, $user, $data, $document, $contracts, $text, $hash) {
            $existing = DB::table('cutinapp_producer_contract_acceptances')
                ->where('production_id', $production->id)
                ->where('contract_version', $contracts->version())
                ->first();
            if ($existing) return $existing;

            $id = DB::table('cutinapp_producer_contract_acceptances')->insertGetId([
                'production_id' => $production->id,
                'user_id' => $user->id,
                'contract_version' => $contracts->version(),
                'contract_hash' => $hash,
                'contract_snapshot' => $text,
                'signer_name' => trim($data['signer_name']),
                'signer_document' => $document,
                'signer_role' => trim((string) ($data['signer_role'] ?? '')) ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('cutinapp_producer_contract_acceptances')->find($id);
        });

        $this->sendCopy($production, $user->email, $acceptance);

        return response()->json([
            'message' => 'Contrato assinado com sucesso. Enviamos uma cópia para o seu e-mail.',
            'contract' => [
                'version' => $acceptance->contract_version,
                'hash' => $acceptance->contract_hash,
                'accepted' => true,
                'accepted_at' => $acceptance->accepted_at,
                'signer_name' => $acceptance->signer_name,
            ],
        ]);
    }

    public function pdf(Request $request, int $productionId, CutinappProducerContractService $contracts)
    {
        $production = $this->ownedProduction($request, $productionId);
        $acceptance = DB::table('cutinapp_producer_contract_acceptances')
            ->where('production_id', $production->id)
            ->where('contract_version', $contracts->version())
            ->firstOrFail();

        return Pdf::loadView('pdf.cutinapp-producer-contract', compact('production', 'acceptance'))
            ->setPaper('a4')
            ->download('cutinapp-contrato-produtor-' . $production->id . '.pdf');
    }

    private function sendCopy(Production $production, string $email, object $acceptance): void
    {
        try {
            $pdf = Pdf::loadView('pdf.cutinapp-producer-contract', compact('production', 'acceptance'))->output();
            Mail::send('emails.cutinapp-producer-welcome', ['production' => $production, 'acceptance' => $acceptance], function ($message) use ($email, $production, $pdf) {
                $message->to($email)
                    ->subject('Bem-vindo à Cutinapp — contrato da produção ' . $production->name)
                    ->attachData($pdf, 'contrato-cutinapp-' . $production->id . '.pdf', ['mime' => 'application/pdf']);
            });
            DB::table('cutinapp_producer_contract_acceptances')->where('id', $acceptance->id)->update(['email_sent_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ownedProduction(Request $request, int $productionId): Production
    {
        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->firstOrFail();
        $production = Production::query()->where('id', $productionId)->where('app_id', $application->id)->where('app_slug', self::APP)->firstOrFail();
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless($admin || (int) $production->user_id === (int) $request->user()->id, 403, 'Você não pode representar esta produção.');
        return $production;
    }
}
