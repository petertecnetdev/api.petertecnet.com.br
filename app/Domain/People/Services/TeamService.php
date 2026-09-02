<?php

namespace App\Domain\People\Services;

use App\Models\PeopleProfile;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TeamService
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Team::query()->with('members')->where('status', 'active');
        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['q'])) {
            $term = '%' . trim((string) $filters['q']) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        return $query->orderBy('name')->paginate($perPage);
    }

    public function create(array $attributes, ?int $ownerUserId = null): Team
    {
        $name = trim((string) $attributes['name']);
        return Team::create([
            'application_id' => $this->applicationContext->id(),
            'organization_id' => $attributes['organization_id'] ?? null,
            'owner_user_id' => $attributes['owner_user_id'] ?? $ownerUserId,
            'name' => $name,
            'slug' => $attributes['slug'] ?? $this->uniqueSlug($name),
            'type' => $attributes['type'] ?? 'team',
            'status' => $attributes['status'] ?? 'active',
            'metadata' => $attributes['metadata'] ?? [],
        ]);
    }

    public function syncMembers(Team $team, array $members): Team
    {
        abort_unless((int) $team->application_id === $this->applicationContext->id(), 404);

        DB::transaction(function () use ($team, $members) {
            $ids = collect($members)->pluck('person_profile_id')->map(fn ($id) => (int) $id)->unique()->values();
            $valid = PeopleProfile::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            abort_unless($valid->all() === $ids->sort()->values()->all(), 422, 'Um ou mais integrantes não pertencem ao aplicativo atual.');

            TeamMembership::query()->where('team_id', $team->id)->delete();
            foreach ($members as $member) {
                TeamMembership::create([
                    'team_id' => $team->id,
                    'person_profile_id' => (int) $member['person_profile_id'],
                    'role' => $member['role'] ?? 'member',
                    'status' => $member['status'] ?? 'active',
                    'metadata' => $member['metadata'] ?? [],
                    'joined_at' => now(),
                ]);
            }
        });

        return $team->fresh('members');
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'team';
        $slug = $base;
        $counter = 2;
        while (Team::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }
}
