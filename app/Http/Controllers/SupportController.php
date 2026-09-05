<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    private const CATEGORIES = [
        'general', 'access', 'account', 'technical', 'billing', 'bug', 'suggestion', 'security',
    ];

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

        $user = $this->optionalUser($request);
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if (! $user && ($name === '' || $email === '')) {
            return response()->json([
                'message' => 'Informe nome e e-mail para abrir um chamado sem estar autenticado.',
                'errors' => [
                    'name' => $name === '' ? ['O nome é obrigatório.'] : [],
                    'email' => $email === '' ? ['O e-mail é obrigatório.'] : [],
                ],
            ], 422);
        }

        $application = $this->resolveApplication($request, $data);
        $accessToken = Str::random(64);
        $now = now();

        $ticket = DB::transaction(function () use ($data, $user, $application, $accessToken, $name, $email, $now) {
            $ticket = SupportTicket::create([
                'public_id' => (string) Str::uuid(),
                'access_token_hash' => hash('sha256', $accessToken),
                'user_id' => $user?->id,
                'application_id' => $application?->id,
                'establishment_id' => $data['establishment_id'] ?? null,
                'requester_name' => $user ? $this->userName($user) : $name,
                'requester_email' => $user ? strtolower((string) $user->email) : $email,
                'requester_phone' => $data['phone'] ?? ($user?->phone ?? null),
                'subject' => trim($data['subject']),
                'category' => $data['category'] ?? 'general',
                'priority' => ($data['category'] ?? null) === 'security' ? 'high' : 'normal',
                'status' => 'open',
                'channel' => $data['channel'] ?? 'web',
                'source_url' => $data['source_url'] ?? null,
                'metadata' => array_filter([
                    ...($data['metadata'] ?? []),
                    'source_app' => $application?->slug ?? $this->sourceAppSlug($request, $data),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
                ], static fn ($value) => $value !== null && $value !== ''),
                'last_message_at' => $now,
            ]);

            SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $user?->id,
                'author_type' => 'requester',
                'body' => trim($data['message']),
                'is_internal' => false,
                'metadata' => ['channel' => $ticket->channel],
            ]);

            return $ticket;
        });

        $ticket->load(['application:id,name,slug', 'establishment:id,name,fantasy', 'messages']);

        return response()->json([
            'message' => 'Chamado aberto com sucesso.',
            'ticket' => $this->publicTicket($ticket),
            'access_token' => $accessToken,
        ], 201);
    }

    public function show(Request $request, string $publicId)
    {
        $ticket = SupportTicket::query()
            ->where('public_id', $publicId)
            ->with([
                'application:id,name,slug',
                'establishment:id,name,fantasy',
                'messages' => fn ($query) => $query->where('is_internal', false)->orderBy('created_at'),
            ])
            ->firstOrFail();

        $this->authorizeRequester($request, $ticket);

        return response()->json(['ticket' => $this->publicTicket($ticket)]);
    }

    public function reply(Request $request, string $publicId)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
        ]);

        $ticket = SupportTicket::query()->where('public_id', $publicId)->firstOrFail();
        $user = $this->authorizeRequester($request, $ticket);

        DB::transaction(function () use ($ticket, $user, $data) {
            SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $user?->id,
                'author_type' => 'requester',
                'body' => trim($data['message']),
                'is_internal' => false,
            ]);

            $updates = ['last_message_at' => now()];
            if (in_array($ticket->status, ['waiting_customer', 'resolved', 'closed'], true)) {
                $updates['status'] = 'open';
                $updates['resolved_at'] = null;
                $updates['closed_at'] = null;
            }
            $ticket->update($updates);
        });

        $ticket->refresh()->load([
            'application:id,name,slug',
            'establishment:id,name,fantasy',
            'messages' => fn ($query) => $query->where('is_internal', false)->orderBy('created_at'),
        ]);

        return response()->json([
            'message' => 'Mensagem enviada ao suporte.',
            'ticket' => $this->publicTicket($ticket),
        ]);
    }

    public function my(Request $request)
    {
        $user = $request->user('api');
        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->with(['application:id,name,slug'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));

        $tickets->getCollection()->transform(fn (SupportTicket $ticket) => $this->publicTicket($ticket));

        return response()->json($tickets);
    }

    private function resolveApplication(Request $request, array $data): ?Application
    {
        if (! empty($data['application_id'])) {
            return Application::query()->find($data['application_id']);
        }

        $slug = $this->sourceAppSlug($request, $data);
        if ($slug === '') {
            return null;
        }

        return Application::query()->whereRaw('LOWER(slug) = ?', [$slug])->first();
    }

    private function sourceAppSlug(Request $request, array $data): string
    {
        return strtolower(trim((string) ($data['application_slug'] ?? $request->header('X-Peter-App', ''))));
    }

    private function optionalUser(Request $request)
    {
        try {
            return $request->user('api');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function authorizeRequester(Request $request, SupportTicket $ticket)
    {
        $user = $this->optionalUser($request);
        if ($user && (int) $ticket->user_id === (int) $user->id) {
            return $user;
        }

        $token = trim((string) ($request->header('X-Support-Token') ?: $request->query('token', '')));
        $valid = $token !== ''
            && $ticket->access_token_hash
            && hash_equals((string) $ticket->access_token_hash, hash('sha256', $token));

        abort_unless($valid, 403, 'Credencial de acompanhamento do chamado inválida.');

        return null;
    }

    private function userName($user): string
    {
        $name = trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null])));

        return $name !== '' ? $name : ((string) ($user->user_name ?: $user->email));
    }

    private function publicTicket(SupportTicket $ticket): array
    {
        $messages = $ticket->relationLoaded('messages')
            ? $ticket->messages->where('is_internal', false)->values()->map(fn (SupportMessage $message) => [
                'id' => $message->id,
                'author_type' => $message->author_type,
                'author_name' => $message->author_type === 'agent' ? 'Equipe Peter Tecnet' : $ticket->requester_name,
                'body' => $message->body,
                'created_at' => $message->created_at,
            ])->all()
            : null;

        return array_filter([
            'id' => $ticket->id,
            'public_id' => $ticket->public_id,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'channel' => $ticket->channel,
            'requester_name' => $ticket->requester_name,
            'application' => $ticket->application ? [
                'id' => $ticket->application->id,
                'name' => $ticket->application->name,
                'slug' => $ticket->application->slug,
            ] : null,
            'establishment' => $ticket->establishment ? [
                'id' => $ticket->establishment->id,
                'name' => $ticket->establishment->fantasy ?: $ticket->establishment->name,
            ] : null,
            'last_message_at' => $ticket->last_message_at,
            'first_response_at' => $ticket->first_response_at,
            'resolved_at' => $ticket->resolved_at,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'messages' => $messages,
        ], static fn ($value) => $value !== null);
    }
}
