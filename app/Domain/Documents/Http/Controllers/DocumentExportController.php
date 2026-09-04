<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DocumentExportController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function pdf(Request $request, string $publicId)
    {
        $document = DB::table('documents')->where('public_id', $publicId)->where('app_id', $this->context->id())->whereNull('deleted_at')->firstOrFail();
        $this->authorizeDocument($request, $document);

        $requestedVersion = $request->integer('version');
        $version = DB::table('document_versions')->where('document_id', $document->id)
            ->when($requestedVersion > 0, fn ($q) => $q->where('version', $requestedVersion), fn ($q) => $q->where('version', $document->current_version))
            ->firstOrFail();
        $parties = DB::table('document_parties')->where('document_id', $document->id)->orderBy('signing_order')->get();
        $signatures = DB::table('document_signatures')->where('document_version_id', $version->id)->get()->keyBy('document_party_id');

        $pdf = Pdf::loadHTML($this->html($document, $version, $parties, $signatures))->setPaper('a4', 'portrait');
        $filename = Str::slug($document->title ?: 'documento').'-v'.$version->version.'.pdf';
        return $pdf->download($filename);
    }

    private function authorizeDocument(Request $request, object $document): void
    {
        $userId = (int) $request->user()->id;
        $party = DB::table('document_parties')->where('document_id', $document->id)->where('user_id', $userId)->exists();
        $creator = (int) $document->created_by_user_id === $userId;
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless($party || $creator || $admin, 403);
    }

    private function html(object $document, object $version, $parties, $signatures): string
    {
        $title = e($document->title); $content = nl2br(e($version->content)); $hash = e($version->content_hash);
        $rows = $parties->map(function ($party) use ($signatures) {
            $signature = $signatures->get($party->id);
            $status = $signature ? 'Assinado em '.e((string) $signature->signed_at) : 'Pendente';
            $evidence = $signature ? '<br><small>Assinatura: '.e(substr($signature->signature_hash, 0, 24)).'…</small>' : '';
            return '<tr><td>'.e($party->role).'</td><td>'.e($party->name).'</td><td>'.$status.$evidence.'</td></tr>';
        })->implode('');

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>
            @page{margin:22mm 18mm}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:10.5pt;line-height:1.58}
            h1{font-size:17pt;margin:0 0 4px}.meta{color:#667085;font-size:8.5pt;margin-bottom:20px}.document{white-space:normal}
            table{width:100%;border-collapse:collapse;margin-top:24px;font-size:8.5pt}th,td{border:1px solid #d9dee7;padding:7px;text-align:left}th{background:#f4f6f9}
            .integrity{margin-top:18px;padding:9px;border:1px solid #d9dee7;background:#f8fafc;font-size:8pt;word-break:break-all}
            footer{margin-top:18px;color:#7a8494;font-size:7.5pt;text-align:center}
        </style></head><body><h1>'.$title.'</h1><div class="meta">Versão '.$version->version.' · status '.e($version->status).' · documento '.e($document->public_id).'</div>
        <div class="document">'.$content.'</div><table><thead><tr><th>Parte</th><th>Nome</th><th>Assinatura</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <div class="integrity"><strong>Integridade SHA-256</strong><br>'.$hash.'</div><footer>Documento eletrônico gerado pela infraestrutura Peter Tecnet.</footer></body></html>';
    }
}
