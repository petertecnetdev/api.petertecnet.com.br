<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminSupportService
{
    private const STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function summary(): array
    {
        $activeStatuses = ['open', 'in_progress', 'waiting_customer'];
        $base = SupportTicket::query();
        return [
            'active' => (clone $base)->whereIn('status', $activeStatuses)->count(),
            'open' => (clone $base)->where('status', 'open')->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'waiting_customer' => (clone $base)->where('status', 'waiting_customer')->count(),
            'unassigned' => (clone $base)->whereIn('status', $activeStatuses)->whereNull('assigned_to_user_id')->count(),
            'urgent' => (clone $base)->whereIn('status', $activeStatuses)->where('priority', 'urgent')->count(),
            'high_priority' => (clone $base)->whereIn('status', $activeStatuses)->whereIn('priority', ['high', 'urgent'])->count(),
            'resolved_today' => (clone $base)->whereDate('resolved_at', now()->toDateString())->count(),
            'created_today' => (clone $base)->whereDate('created_at', now()->toDateString())->count(),
        ];
    }

    public function index(Request $request)
    {
        $query = SupportTicket::query()->with([
            'application:id,name,slug', 'establishment:id,name,fantasy',
            'requester:id,first_name,last_name,user_name,email', 'assignee:id,first_name,last_name,user_name,email',
        ]);
        if ($request->filled('status')) {
            $values = array_values(array_intersect(explode(',', (string) $request->query('status')), self::STATUSES));
            if ($values) $query->whereIn('status', $values);
        }
        if ($request->filled('priority')) {
            $values = array_values(array_intersect(explode(',', (string) $request->query('priority')), self::PRIORITIES));
            if ($values) $query->whereIn('priority', $values);
        }
        if ($request->filled('category')) $query->where('category', (string) $request->query('category'));
        if ($request->filled('application_id')) $query->where('application_id', (int) $request->query('application_id'));
        if ($request->filled('assigned_to_user_id')) {
            $value = (string) $request->query('assigned_to_user_id');
            $value === 'unassigned' ? $query->whereNull('assigned_to_user_id') : $query->where('assigned_to_user_id', (int) $value);
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($inner) use ($term) {
                $like = '%'.$term.'%';
                $inner->where('subject', 'like', $like)->orWhere('public_id', 'like', $like)
                    ->orWhere('requester_name', 'like', $like)->orWhere('requester_email', 'like', $like)
                    ->orWhereHas('messages', fn ($messages) => $messages->where('body', 'like', $like));
            });
        }
        $tickets = $query
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 30), 1), 100));
        $tickets->getCollection()->transform(fn (SupportTicket $ticket) => $this->ticketPayload($ticket, false));
        return $tickets;
    }

    public function show(SupportTicket $ticket): array
    {
        $ticket->load([
            'application:id,name,slug', 'establishment:id,name,fantasy',
            'requester:id,first_name,last_name,user_name,email,phone',
            'assignee:id,first_name,last_name,user_name,email',
            'messages.user:id,first_name,last_name,user_name,email',
        ]);
        return $this->ticketPayload($ticket, true);
    }

    public function update(User $actor, SupportTicket $ticket, array $data): array
    {
        $before = $ticket->only(['status', 'priority', 'category', 'assigned_to_user_id']);
        $updates = $data;
        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'resolved' && $ticket->status !== 'resolved') {
                $updates['resolved_at'] = now(); $updates['closed_at'] = null;
            } elseif ($data['status'] === 'closed' && $ticket->status !== 'closed') {
                $updates['closed_at'] = now(); $updates['resolved_at'] = $ticket->resolved_at ?: now();
            } elseif (in_array($data['status'], ['open', 'in_progress', 'waiting_customer'], true)) {
                $updates['resolved_at'] = null; $updates['closed_at'] = null;
            }
        }
        DB::transaction(function () use ($actor, $ticket, $updates, $before) {
            $ticket->update($updates);
            $after = $ticket->fresh()->only(['status', 'priority', 'category', 'assigned_to_user_id']);
            $changes = [];
            foreach ($after as $key => $value) if (($before[$key] ?? null) !== $value) $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            if ($changes) SupportMessage::create([
                'support_ticket_id' => $ticket->id, 'user_id' => $actor->id, 'author_type' => 'system',
                'body' => 'Chamado atualizado pela equipe de suporte.', 'is_internal' => true, 'metadata' => ['changes' => $changes],
            ]);
        });
        return $this->show($ticket->fresh());
    }

    public function reply(?User $actor, SupportTicket $ticket, array $data): array
    {
        $internal = (bool) ($data['is_internal'] ?? false);
        DB::transaction(function () use ($ticket, $actor, $data, $internal) {
            SupportMessage::create([
                'support_ticket_id' => $ticket->id, 'user_id' => $actor?->id, 'author_type' => 'agent',
                'body' => trim($data['message']), 'is_internal' => $internal, 'metadata' => ['channel' => 'admin_center'],
            ]);
            $updates = ['last_message_at' => now()];
            if (! $internal) {
                $updates['first_response_at'] = $ticket->first_response_at ?: now();
                $updates['status'] = $data['status'] ?? ($ticket->status === 'open' ? 'in_progress' : $ticket->status);
            } elseif (! empty($data['status'])) $updates['status'] = $data['status'];
            if (($updates['status'] ?? null) === 'resolved') {
                $updates['resolved_at'] = now(); $updates['closed_at'] = null;
            } elseif (($updates['status'] ?? null) === 'closed') {
                $updates['closed_at'] = now(); $updates['resolved_at'] = $ticket->resolved_at ?: now();
            }
            $ticket->update($updates);
        });
        return $this->show($ticket->fresh());
    }

    private function ticketPayload(SupportTicket $ticket, bool $withMessages): array
    {
        $payload = [
            'id' => $ticket->id, 'public_id' => $ticket->public_id, 'subject' => $ticket->subject,
            'category' => $ticket->category, 'priority' => $ticket->priority, 'status' => $ticket->status,
            'channel' => $ticket->channel, 'requester_name' => $ticket->requester_name,
            'requester_email' => $ticket->requester_email, 'requester_phone' => $ticket->requester_phone,
            'source_url' => $ticket->source_url, 'metadata' => $ticket->metadata,
            'application' => $ticket->application ? ['id' => $ticket->application->id, 'name' => $ticket->application->name, 'slug' => $ticket->application->slug] : null,
            'establishment' => $ticket->establishment ? ['id' => $ticket->establishment->id, 'name' => $ticket->establishment->fantasy ?: $ticket->establishment->name] : null,
            'requester' => $ticket->requester ? ['id' => $ticket->requester->id, 'name' => $this->userName($ticket->requester), 'email' => $ticket->requester->email] : null,
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $this->userName($ticket->assignee), 'email' => $ticket->assignee->email] : null,
            'last_message_at' => $ticket->last_message_at, 'first_response_at' => $ticket->first_response_at,
            'resolved_at' => $ticket->resolved_at, 'closed_at' => $ticket->closed_at,
            'created_at' => $ticket->created_at, 'updated_at' => $ticket->updated_at,
        ];
        if ($withMessages) $payload['messages'] = $ticket->messages->map(fn (SupportMessage $message) => [
            'id' => $message->id, 'author_type' => $message->author_type,
            'author_name' => $message->author_type === 'agent' ? ($message->user ? $this->userName($message->user) : 'Equipe Peter Tecnet') : ($message->author_type === 'system' ? 'Sistema' : $ticket->requester_name),
            'body' => $message->body, 'is_internal' => $message->is_internal, 'metadata' => $message->metadata, 'created_at' => $message->created_at,
        ])->all();
        return $payload;
    }

    private function userName($user): string
    {
        $name = trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null])));
        return $name !== '' ? $name : ((string) ($user->user_name ?: $user->email));
    }
}
