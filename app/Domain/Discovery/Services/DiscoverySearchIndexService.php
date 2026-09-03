<?php

namespace App\Domain\Discovery\Services;

use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscoverySearchIndexService
{
    public function rebuild(): int
    {
        $rows = collect();
        Application::query()->where('is_active', true)->get()->each(fn ($row) => $rows->push($this->doc('application', $row->id, $row->id, $row->name, $row->description, null, null, '/plataformas/' . $row->slug, 30, ['slug' => $row->slug])));
        ContentEntry::query()->published()->limit(4000)->get()->each(fn ($row) => $rows->push($this->doc('content', $row->id, $row->application_id, $row->title, $row->excerpt ?: Str::limit(strip_tags((string) $row->content), 320), $row->category, null, '/blog/' . $row->slug, 24, ['cluster' => $row->cluster, 'tags' => $row->tags, 'search_intent' => $row->search_intent])));
        Establishment::query()->where('is_cancelled', false)->where('is_published', true)->limit(5000)->get()->each(function ($row) use ($rows) {
            $rows->push($this->doc('establishment', $row->id, $row->app_id, $row->fantasy ?: $row->name, $row->description, $row->category ?: $row->type, $row->city, '/empresas/' . $row->slug, 22, ['uf' => $row->uf]));
        });
        Item::query()->with('establishment:id,app_id,name,fantasy,slug,city,uf,is_published,is_cancelled')->where('status', true)->where('entity_name', 'establishment')
            ->whereHas('establishment', fn ($query) => $query->where('is_cancelled', false)->where('is_published', true))->limit(12000)->get()->each(function ($row) use ($rows) {
                $est = $row->establishment;
                $rows->push($this->doc('item', $row->id, $row->app_id ?: $est?->app_id, $row->name, $row->description, $row->category ?: $row->type, $est?->city, '/solucoes/' . ($row->slug ?: $row->id), 28, ['price' => $row->price, 'brand' => $row->brand, 'establishment' => $est ? ['id' => $est->id, 'name' => $est->fantasy ?: $est->name, 'slug' => $est->slug, 'uf' => $est->uf] : null]));
            });
        DB::transaction(function () use ($rows) {
            DB::table('discovery_search_documents')->delete();
            $rows->chunk(500)->each(fn ($chunk) => DB::table('discovery_search_documents')->insert($chunk->all()));
        });
        Cache::put('discovery-search-index-version', (string) Str::uuid(), now()->addDays(30));
        return $rows->count();
    }

    public function search(string $term, ?string $city = null, ?int $applicationId = null, int $limit = 8): array
    {
        if (! DB::table('discovery_search_documents')->exists()) $this->rebuild();
        $normalized = $this->normalize($term);
        $tokens = collect(preg_split('/\s+/', $normalized))->filter(fn ($token) => mb_strlen($token) >= 2)->unique()->values();
        $version = Cache::get('discovery-search-index-version', '1');
        $key = 'discovery-ranked:' . sha1(json_encode([$version, $normalized, $city, $applicationId, $limit]));
        return Cache::remember($key, now()->addMinutes(3), function () use ($normalized, $tokens, $city, $applicationId, $limit) {
            $query = DB::table('discovery_search_documents');
            if ($applicationId) $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhere('application_id', $applicationId));
            if ($city) $query->where(fn ($scope) => $scope->whereNull('city')->orWhere('city', 'like', '%' . $city . '%'));
            $query->where(function ($scope) use ($tokens, $normalized) {
                $scope->where('title', 'like', '%' . $normalized . '%')->orWhere('search_text', 'like', '%' . $normalized . '%');
                foreach ($tokens as $token) $scope->orWhere('title', 'like', '%' . $token . '%')->orWhere('search_text', 'like', '%' . $token . '%');
            });
            $documents = $query->limit(250)->get()->map(function ($row) use ($normalized, $tokens, $city) {
                $title = $this->normalize($row->title); $text = $this->normalize($row->search_text); $score = (int) $row->boost;
                if ($title === $normalized) $score += 80; elseif (str_starts_with($title, $normalized)) $score += 55; elseif (str_contains($title, $normalized)) $score += 38;
                if (str_contains($text, $normalized)) $score += 24;
                foreach ($tokens as $token) {
                    if (str_contains($title, $token)) $score += 12; elseif (str_contains($text, $token)) $score += 5;
                    else foreach (preg_split('/\s+/', $title) as $word) { if (mb_strlen($word) > 3 && levenshtein($token, $word) <= 1) { $score += 4; break; } }
                }
                if ($city && $row->city && Str::contains(Str::lower($row->city), Str::lower($city))) $score += 18;
                $row->score = $score; $row->metadata = json_decode($row->metadata ?: 'null', true); return $row;
            })->sortByDesc('score')->take($limit * 4)->values();
            return ['query' => $normalized, 'count' => $documents->count(), 'results' => $documents->groupBy('document_type')->map(fn ($group) => $group->take($limit)->map(fn ($row) => ['type' => $row->document_type, 'id' => $row->document_id, 'title' => $row->title, 'description' => $row->summary, 'category' => $row->category, 'location' => $row->city, 'url' => $row->url, 'score' => $row->score, 'application_id' => $row->application_id, 'metadata' => $row->metadata])->values())->all()];
        });
    }

    private function doc(string $type, int $id, ?int $applicationId, string $title, ?string $summary, ?string $category, ?string $city, string $url, int $boost, array $metadata): array
    {
        $text = implode(' ', array_filter([$title, $summary, $category, $city, json_encode($metadata, JSON_UNESCAPED_UNICODE)]));
        return ['application_id' => $applicationId, 'document_type' => $type, 'document_id' => $id, 'title' => $title, 'summary' => $summary, 'search_text' => $this->normalize($text), 'category' => $category, 'city' => $city, 'url' => $url, 'boost' => $boost, 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
