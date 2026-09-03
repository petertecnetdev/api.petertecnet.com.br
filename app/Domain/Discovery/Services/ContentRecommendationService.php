<?php

namespace App\Domain\Discovery\Services;

use App\Models\ContentEntry;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentRecommendationService
{
    public function items(ContentEntry $entry, int $limit = 6): Collection
    {
        $terms = collect(array_merge(
            $entry->tags ?: [],
            [$entry->category, $entry->cluster, $entry->search_intent]
        ))
            ->filter()
            ->flatMap(fn ($value) => preg_split('/[^\pL\pN]+/u', Str::lower((string) $value)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => mb_strlen($value) >= 4)
            ->reject(fn ($value) => in_array($value, ['para', 'como', 'sobre', 'empresa', 'empresas', 'tecnologia'], true))
            ->unique()
            ->take(10)
            ->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $query = Item::query()
            ->with([
                'files',
                'establishment' => fn ($establishments) => $establishments->select(['id', 'name', 'fantasy', 'slug', 'city', 'uf']),
            ])
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $establishments) use ($entry) {
                $establishments->where('is_cancelled', false)->where('is_published', true);
                if ($entry->application_id) {
                    $establishments->forApplication((int) $entry->application_id);
                }
            })
            ->where(function (Builder $search) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $search->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(category) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(subcategory) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(brand) LIKE ?', [$like]);
                }
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $query->each(fn (Item $item) => $item->setAppends(['image_url']));

        return $query;
    }
}