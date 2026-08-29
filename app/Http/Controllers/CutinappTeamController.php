<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CutinappTeamController extends Controller
{
    private function eventOrFail(int $eventId): Event
    {
        return Event::query()->with('production')->findOrFail($eventId);
    }

    private function guardManage(Event $event): void
    {
        $userId = (int) auth()->id();
        $allowed = (int) optional($event->production)->user_id === $userId
            || DB::table('cutinapp_event_members')
                ->where('event_id', $event->id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->whereIn('role', ['producer', 'manager'])
                ->exists();

        abort_unless($allowed, 403, 'Você não pode gerenciar a equipe deste evento.');
    }

    private function resolveUserId(?int $userId, ?string $email): ?int
    {
        if ($userId) return $userId;
        if (! $email) return null;

        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->value('id');
    }

    public function storeMember(Request $request, int $eventId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::in(['producer','manager','promoter','supplier','collaborator','checker'])],
            'permissions' => 'nullable|array',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data['email'] = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        $data['user_id'] = $this->resolveUserId($data['user_id'] ?? null, $data['email'] ?? null);

        $existing = DB::table('cutinapp_event_members')
            ->where('event_id', $eventId)
            ->where('role', $data['role'])
            ->when($data['user_id'], fn ($q) => $q->where('user_id', $data['user_id']))
            ->when(! $data['user_id'] && $data['email'], fn ($q) => $q->whereRaw('LOWER(email) = ?', [$data['email']]))
            ->first();

        if ($existing) {
            DB::table('cutinapp_event_members')->where('id', $existing->id)->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? $existing->email,
                'phone' => $data['phone'] ?? $existing->phone,
                'user_id' => $data['user_id'] ?? $existing->user_id,
                'status' => 'active',
                'permissions' => isset($data['permissions']) ? json_encode($data['permissions']) : $existing->permissions,
                'notes' => $data['notes'] ?? $existing->notes,
                'updated_at' => now(),
            ]);
            return response()->json(DB::table('cutinapp_event_members')->find($existing->id));
        }

        $id = DB::table('cutinapp_event_members')->insertGetId([
            'event_id' => $eventId,
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'status' => 'active',
            'permissions' => isset($data['permissions']) ? json_encode($data['permissions']) : null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(DB::table('cutinapp_event_members')->find($id), 201);
    }

    public function updateMember(Request $request, int $eventId, int $memberId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $member = DB::table('cutinapp_event_members')->where('event_id', $eventId)->find($memberId);
        abort_unless($member, 404, 'Membro não encontrado.');

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:30',
            'role' => ['sometimes', Rule::in(['producer','manager','promoter','supplier','collaborator','checker'])],
            'status' => ['sometimes', Rule::in(['invited','active','suspended','removed'])],
            'permissions' => 'nullable|array',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (array_key_exists('email', $data)) {
            $data['email'] = $data['email'] ? strtolower(trim($data['email'])) : null;
            $data['user_id'] = $this->resolveUserId(null, $data['email']);
        }
        if (array_key_exists('permissions', $data)) $data['permissions'] = json_encode($data['permissions']);
        $data['updated_at'] = now();

        DB::table('cutinapp_event_members')->where('id', $memberId)->update($data);
        return response()->json(DB::table('cutinapp_event_members')->find($memberId));
    }

    public function storePromoter(Request $request, int $eventId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $data = $request->validate([
            'member_id' => 'required|integer|exists:cutinapp_event_members,id',
            'commission_type' => ['required', Rule::in(['percentage','fixed'])],
            'commission_value' => 'required|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        $member = DB::table('cutinapp_event_members')
            ->where('event_id', $eventId)
            ->where('role', 'promoter')
            ->find($data['member_id']);
        abort_unless($member, 422, 'O membro informado não é promoter deste evento.');

        $existing = DB::table('cutinapp_promoters')
            ->where('event_id', $eventId)
            ->where('member_id', $member->id)
            ->first();

        if ($existing) {
            DB::table('cutinapp_promoters')->where('id', $existing->id)->update([
                'user_id' => $member->user_id,
                'commission_type' => $data['commission_type'],
                'commission_value' => $data['commission_value'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'active' => true,
                'updated_at' => now(),
            ]);
            return response()->json(DB::table('cutinapp_promoters')->find($existing->id));
        }

        $id = DB::table('cutinapp_promoters')->insertGetId([
            'event_id' => $eventId,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'code' => strtoupper(Str::random(10)),
            'commission_type' => $data['commission_type'],
            'commission_value' => $data['commission_value'],
            'active' => true,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(DB::table('cutinapp_promoters')->find($id), 201);
    }
}
