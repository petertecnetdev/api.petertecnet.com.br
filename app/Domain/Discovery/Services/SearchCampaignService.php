<?php

namespace App\Domain\Discovery\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SearchCampaignService
{
    public function active(int $appId, string $normalizedQuery, ?string $city, ?string $uf, int $limit = 3): Collection
    {
        if (! Schema::hasTable('search_campaigns')) {
            return collect();
        }

        $rows = DB::table('search_campaigns')
            ->where('app_id', $appId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('budget_cents')->orWhereColumn('spent_cents', '<', 'budget_cents'))
            ->when($city, fn ($q) => $q->where(fn ($x) => $x->whereNull('city')->orWhereRaw('LOWER(city) = LOWER(?)', [$city])))
            ->when($uf, fn ($q) => $q->where(fn ($x) => $x->whereNull('uf')->orWhere('uf', strtoupper($uf))))
            ->orderByDesc('priority')
            ->orderByDesc('bid_cents')
            ->limit(30)
            ->get()
            ->filter(function ($row) use ($normalizedQuery) {
                $keywords = json_decode((string) $row->keywords, true) ?: [];
                if (! $keywords) {
                    return true;
                }

                foreach ($keywords as $keyword) {
                    $keyword = mb_strtolower(trim((string) $keyword));
                    if ($keyword !== '' && str_contains($normalizedQuery, $keyword)) {
                        return true;
                    }
                }

                return false;
            })
            ->take(max(1, min(5, $limit)))
            ->values();

        $resolved = $rows->map(fn ($row) => $this->resolve($appId, $row))->filter()->values();

        if ($resolved->isNotEmpty()) {
            $ids = $rows->pluck('id')->all();
            DB::table('search_campaigns')->whereIn('id', $ids)->increment('impressions');
        }

        return $resolved;
    }

    public function list(int $appId): Collection
    {
        if (! Schema::hasTable('search_campaigns')) {
            return collect();
        }

        return DB::table('search_campaigns')
            ->where('app_id', $appId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => $this->row($row));
    }

    public function create(int $appId, int $userId, array $data): array
    {
        abort_unless(Schema::hasTable('search_campaigns'), 503, 'Campanhas de busca ainda não foram migradas.');

        $id = DB::table('search_campaigns')->insertGetId([
            'app_id' => $appId,
            'owner_user_id' => $data['owner_user_id'] ?? $userId,
            'production_id' => $data['production_id'] ?? null,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'label' => $data['label'] ?? null,
            'keywords' => json_encode(array_values(array_unique($data['keywords'] ?? [])), JSON_UNESCAPED_UNICODE),
            'city' => $data['city'] ?? null,
            'uf' => isset($data['uf']) ? strtoupper($data['uf']) : null,
            'priority' => (int) ($data['priority'] ?? 0),
            'bid_cents' => (int) ($data['bid_cents'] ?? 0),
            'budget_cents' => $data['budget_cents'] ?? null,
            'spent_cents' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'status' => $data['status'] ?? 'draft',
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->row(DB::table('search_campaigns')->find($id));
    }

    public function update(int $appId, int $id, array $data): array
    {
        abort_unless(Schema::hasTable('search_campaigns'), 503, 'Campanhas de busca ainda não foram migradas.');

        $campaign = DB::table('search_campaigns')->where('app_id', $appId)->where('id', $id)->first();
        abort_unless($campaign, 404, 'Campanha não encontrada.');

        $allowed = collect([
            'owner_user_id', 'production_id', 'target_type', 'target_id', 'label', 'city', 'priority',
            'bid_cents', 'budget_cents', 'status', 'starts_at', 'ends_at',
        ])->filter(fn ($key) => array_key_exists($key, $data))->mapWithKeys(fn ($key) => [$key => $data[$key]])->all();

        if (array_key_exists('keywords', $data)) {
            $allowed['keywords'] = json_encode(array_values(array_unique($data['keywords'] ?? [])), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('uf', $data)) {
            $allowed['uf'] = $data['uf'] ? strtoupper($data['uf']) : null;
        }
        $allowed['updated_at'] = now();

        DB::table('search_campaigns')->where('id', $id)->update($allowed);

        return $this->row(DB::table('search_campaigns')->find($id));
    }

    public function delete(int $appId, int $id): bool
    {
        if (! Schema::hasTable('search_campaigns')) {
            return false;
        }

        return (bool) DB::table('search_campaigns')->where('app_id', $appId)->where('id', $id)->delete();
    }

    private function resolve(int $appId, object $campaign): ?array
    {
        $base = [
            'sponsored' => true,
            'sponsor_label' => 'Patrocinado',
            'campaign_id' => (int) $campaign->id,
            'campaign_priority' => (int) $campaign->priority,
        ];

        return match ($campaign->target_type) {
            'event' => $this->event($appId, (int) $campaign->target_id, $base),
            'production' => $this->production($appId, (int) $campaign->target_id, $base),
            'artist' => $this->artist($appId, (int) $campaign->target_id, $base),
            'user' => $this->user($appId, (int) $campaign->target_id, $base),
            default => null,
        };
    }

    private function event(int $appId, int $id, array $base): ?array
    {
        $event = Event::query()->where('app_id', $appId)->publiclyVisible()->find($id);
        if (! $event) {
            return null;
        }

        return $base + [
            'type' => 'event',
            'id' => (int) $event->id,
            'title' => $event->title,
            'subtitle' => trim(implode(' · ', array_filter([$event->venue, $event->city]))),
            'image' => $event->image,
            'url' => '/event/'.$event->slug,
            'meta' => ['start_date' => optional($event->start_date)->toIso8601String()],
        ];
    }

    private function production(int $appId, int $id, array $base): ?array
    {
        $production = Production::query()->where('app_id', $appId)->where('is_published', true)->find($id);
        if (! $production) {
            return null;
        }

        return $base + [
            'type' => 'production',
            'id' => (int) $production->id,
            'title' => $production->name,
            'subtitle' => trim(implode(' · ', array_filter([$production->fantasy, $production->city]))),
            'image' => $production->logo,
            'url' => '/production/'.$production->slug.'/public',
            'meta' => [],
        ];
    }

    private function artist(int $appId, int $id, array $base): ?array
    {
        $artist = Artist::query()->where('app_id', $appId)->where('is_published', true)->find($id);
        if (! $artist) {
            return null;
        }

        return $base + [
            'type' => 'artist',
            'id' => (int) $artist->id,
            'title' => $artist->stage_name,
            'subtitle' => trim(implode(' · ', array_filter([$artist->artist_type, $artist->city]))),
            'image' => $artist->photo,
            'url' => '/artist/'.$artist->slug,
            'meta' => [],
        ];
    }

    private function user(int $appId, int $id, array $base): ?array
    {
        $user = User::query()
            ->whereKey($id)
            ->whereHas('applications', fn ($q) => $q->where('applications.id', $appId)->where('application_user.status', 'active'))
            ->first();

        if (! $user) {
            return null;
        }

        $name = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));

        return $base + [
            'type' => 'user',
            'id' => (int) $user->id,
            'title' => $name !== '' ? $name : ($user->user_name ?: 'Usuário Cutinapp'),
            'subtitle' => $user->user_name ? '@'.ltrim($user->user_name, '@') : null,
            'image' => $user->avatar,
            'url' => '/profile/'.$user->id,
            'meta' => [],
        ];
    }

    private function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'owner_user_id' => $row->owner_user_id ? (int) $row->owner_user_id : null,
            'production_id' => $row->production_id ? (int) $row->production_id : null,
            'target_type' => $row->target_type,
            'target_id' => (int) $row->target_id,
            'label' => $row->label,
            'keywords' => json_decode((string) $row->keywords, true) ?: [],
            'city' => $row->city,
            'uf' => $row->uf,
            'priority' => (int) $row->priority,
            'bid_cents' => (int) $row->bid_cents,
            'budget_cents' => $row->budget_cents !== null ? (int) $row->budget_cents : null,
            'spent_cents' => (int) $row->spent_cents,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'status' => $row->status,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'created_at' => $row->created_at,
        ];
    }
}
