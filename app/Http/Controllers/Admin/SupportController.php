<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AdminSupportService;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    private const STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const CATEGORIES = ['general', 'access', 'account', 'technical', 'billing', 'bug', 'suggestion', 'security'];

    public function __construct(private readonly AdminSupportService $support) {}

    public function summary()
    {
        return response()->json(['summary' => $this->support->summary()]);
    }

    public function index(Request $request)
    {
        return response()->json($this->support->index($request));
    }

    public function show(SupportTicket $ticket)
    {
        return response()->json(['ticket' => $this->support->show($ticket)]);
    }

    public function update(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
            'priority' => ['sometimes', 'in:'.implode(',', self::PRIORITIES)],
            'category' => ['sometimes', 'in:'.implode(',', self::CATEGORIES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        return response()->json(['ticket' => $this->support->update($request->user('api'), $ticket, $data)]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
            'is_internal' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
        ]);

        return response()->json(['ticket' => $this->support->reply($request->user('api'), $ticket, $data)]);
    }
}
