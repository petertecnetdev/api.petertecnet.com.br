<?php

namespace App\Http\Controllers;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Models\AccountDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AccountDocumentController extends Controller
{
    private const CATEGORIES = ['identity', 'cpf', 'proof_of_address', 'proof_of_income', 'marital_status', 'other'];

    public function __construct(private readonly ManagedFileStorageService $storage) {}

    public function index()
    {
        return response()->json([
            'documents' => AccountDocument::query()
                ->where('user_id', Auth::id())
                ->latest()
                ->get()
                ->map(fn (AccountDocument $document) => $document->safePayload())
                ->values(),
            'categories' => self::CATEGORIES,
            'limits' => ['max_file_size_mb' => 10, 'accepted_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'side' => 'nullable|in:front,back,single',
            'label' => 'nullable|string|max:180',
            'expires_on' => 'nullable|date|after_or_equal:today',
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp|mimetypes:application/pdf,image/jpeg,image/png,image/webp',
        ]);

        $file = $request->file('file');
        $sha256 = $this->storage->fingerprint($file);
        $duplicate = AccountDocument::query()
            ->where('user_id', Auth::id())
            ->where('category', $data['category'])
            ->where('sha256', $sha256)
            ->first();

        if ($duplicate) {
            return response()->json(['message' => 'Este arquivo já foi enviado nesta categoria.', 'document' => $duplicate->safePayload()], 409);
        }

        $stored = $this->storage->store($file, 'private/account-documents/'.Auth::id(), 'local');

        try {
            $document = AccountDocument::create(array_merge($stored, [
                'user_id' => Auth::id(),
                'category' => $data['category'],
                'side' => $data['side'] ?? null,
                'label' => trim((string) ($data['label'] ?? '')) ?: null,
                'status' => 'pending',
                'expires_on' => $data['expires_on'] ?? null,
                'metadata' => [],
            ]));
        } catch (\Throwable $e) {
            $this->storage->delete($stored['storage_disk'], $stored['storage_path']);
            throw $e;
        }

        return response()->json(['message' => 'Documento enviado com segurança.', 'document' => $document->safePayload()], 201);
    }

    public function download(string $uuid)
    {
        $document = $this->owned($uuid);
        $disk = $document->storage_disk ?: 'local';
        abort_unless($this->storage->exists($disk, $document->storage_path), 404, 'Arquivo não encontrado.');
        $document->forceFill(['last_downloaded_at' => now(), 'download_count' => ((int) $document->download_count) + 1])->save();
        $safeName = preg_replace('/[^\pL\pN._()\- ]/u', '_', $document->original_name) ?: 'documento.'.($document->extension ?: 'bin');

        return $this->storage->disk($disk)->download($document->storage_path, $safeName, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(string $uuid)
    {
        $document = $this->owned($uuid);
        $this->storage->delete($document->storage_disk ?: 'local', $document->storage_path);
        $document->delete();

        return response()->json(['message' => 'Documento removido da sua conta.']);
    }

    private function owned(string $uuid): AccountDocument
    {
        return AccountDocument::query()->where('uuid', $uuid)->where('user_id', Auth::id())->firstOrFail();
    }
}
