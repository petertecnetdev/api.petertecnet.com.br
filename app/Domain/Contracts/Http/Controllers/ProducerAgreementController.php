<?php

namespace App\Domain\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Services\ProducerAgreementService;
use App\Support\ApplicationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ProducerAgreementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
    ) {}

    public function show(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $text = $this->agreements->text($organization);
        $acceptance = $this->acceptance($organization->id);

        return response()->json(['agreement' => [
            'version' => $this->agreements->version(),
            'hash' => $this->agreements->hash($text),
            'text' => $text,
            'accepted' => (bool) $acceptance,
            'accepted_at' => $acceptance?->accepted_at,
            'email_sent_at' => $acceptance?->email_sent_at,
            'signer_name' => $acceptance?->signer_name,
            'signer_document' => $acceptance?->signer_document,
            'organization' => $organization->only(['id','name','cnpj','slug']),
        ]]);
    }

    public function sign(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $user = $request->user();
        $data = $request->validate([
            'signer_name' => 'required|string|min:3|max:255',
            'signer_document' => 'required|string|min:5|max:32',
            'signer_role' => 'nullable|string|max:120',
            'accepted' => 'accepted',
        ]);
        $document = preg_replace('/\D+/', '', $data['signer_document']);
        abort_if(strlen($document) < 11 || strlen($document) > 14, 422, 'Informe um CPF ou CNPJ válido do signatário.');
        $text = $this->agreements->text($organization);
        $hash = $this->agreements->hash($text);

        $acceptance = DB::transaction(function () use ($request, $organization, $user, $data, $document, $text, $hash) {
            $existing = $this->acceptance($organization->id);
            if ($existing) return $existing;
            $id = DB::table('contract_acceptances')->insertGetId([
                'app_id' => $this->context->id(),
                'production_id' => $organization->id,
                'user_id' => $user->id,
                'contract_version' => $this->agreements->version(),
                'contract_hash' => $hash,
                'contract_snapshot' => $text,
                'signer_name' => trim($data['signer_name']),
                'signer_document' => $document,
                'signer_role' => trim((string) ($data['signer_role'] ?? '')) ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
                'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('contract_acceptances')->find($id);
        });

        $emailSent = $acceptance->email_sent_at || $this->sendCopy($organization, $user->email, $acceptance);
        return response()->json(['message' => $emailSent ? 'Termo assinado e enviado ao seu e-mail.' : 'Termo assinado. A cópia por e-mail poderá ser reenviada.', 'agreement' => [
            'version' => $acceptance->contract_version, 'hash' => $acceptance->contract_hash, 'accepted' => true,
            'accepted_at' => $acceptance->accepted_at, 'email_sent_at' => $emailSent ? now()->toIso8601String() : null,
            'signer_name' => $acceptance->signer_name,
        ]]);
    }

    public function resend(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $acceptance = $this->acceptance($organization->id);
        abort_unless($acceptance, 404, 'Termo ainda não foi assinado.');
        abort_unless($this->sendCopy($organization, $request->user()->email, $acceptance), 502, 'Não foi possível enviar a cópia do termo agora.');
        return response()->json(['message' => 'Cópia do termo enviada para o seu e-mail.']);
    }

    public function pdf(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $acceptance = $this->acceptance($organization->id);
        abort_unless($acceptance, 404, 'Termo ainda não foi assinado.');
        return Pdf::loadView('pdf.producer-agreement', compact('organization','acceptance'))->setPaper('a4')->download('termo-organizacao-' . $organization->id . '.pdf');
    }

    private function acceptance(int $organizationId): ?object
    {
        return DB::table('contract_acceptances')->where('app_id', $this->context->id())->where('production_id', $organizationId)->where('contract_version', $this->agreements->version())->first();
    }

    private function sendCopy(Production $organization, string $email, object $acceptance): bool
    {
        try {
            $application = $this->context->application();
            $pdf = Pdf::loadView('pdf.producer-agreement', compact('organization','acceptance','application'))->output();
            Mail::send('emails.producer-agreement-welcome', compact('organization','acceptance','application'), function ($message) use ($email, $organization, $application, $pdf) {
                $message->to($email)->subject('Termo de adesão — ' . $application->name)->attachData($pdf, 'termo-organizacao-' . $organization->id . '.pdf', ['mime'=>'application/pdf']);
            });
            DB::table('contract_acceptances')->where('id', $acceptance->id)->update(['email_sent_at'=>now(),'updated_at'=>now()]);
            return true;
        } catch (\Throwable $e) { report($e); return false; }
    }

    private function ownedOrganization(Request $request, int $id): Production
    {
        $organization = Production::query()->where('app_id', $this->context->id())->findOrFail($id);
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless($admin || (int) $organization->user_id === (int) $request->user()->id, 403, 'Você não pode representar esta organização.');
        return $organization;
    }
}
