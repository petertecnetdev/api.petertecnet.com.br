<?php

namespace App\Http\Controllers;

use App\Services\SupportTicketService;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    private const CATEGORIES = ['general', 'access', 'account', 'technical', 'billing', 'bug', 'suggestion', 'security'];

    public function __construct(private readonly SupportTicketService $support) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:12000'],
            'category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'application_slug' => ['nullable', 'string', 'max:100'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'channel' => ['nullable', 'in:web,app,api'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ]);

        return response()->json([
            'message' => 'Chamado aberto com sucesso.',
            ...$this->support->create($request, $data),
        ], 201);
    }

    public function show(Request $request, string $publicId)
    {
        return response()->json(['ticket' => $this->support->show($request, $publicId)]);
    }

    public function reply(Request $request, string $publicId)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:12000']]);
        return response()->json([
            'message' => 'Mensagem enviada ao suporte.',
            'ticket' => $this->support->reply($request, $publicId, $data['message']),
        ]);
    }

    public function my(Request $request)
    {
        return response()->json($this->support->mine($request));
    }
}
