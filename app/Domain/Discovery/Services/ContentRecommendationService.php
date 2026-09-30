<?php

namespace App\Domain\Discovery\Services;

use App\Models\Artist;
use App\Models\ContentEntry;
use App\Models\Event;
use App\Models\Item;
use App\Models\Production;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentRecommendationService
{
    public function forContent(string $slug, ?string $application = null): array
    {
        $entry = ContentEntry::query()
            ->published()
            ->forApplication($application)
            ->where('slug', $slug)
            ->firstOrFail();

        return [
            'related_events' => $this->events($entry)
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'slug' => $event->slug,
                    'title' => $event->title,
                    'description' => $event->description,
                    'category' => $event->category,
                    'image' => $event->image,
                    'start_date' => $event->start_date,
                    'end_date' => $event->end_date,
                    'venue' => $event->venue,
                    'city' => $event->city,
                    'uf' => $event->uf,
                    'country' => $event->country,
                    'production' => $event->production ? [
                        'id' => $event->production->id,
                        'name' => $event->production->name,
                        'fantasy' => $event->production->fantasy,
                        'slug' => $event->production->slug,
                        'logo' => $event->production->logo,
                        'background' => $event->production->background,
                    ] : null,
                ])
                ->values(),
            'related_productions' => $this->productions($entry)
                ->map(fn (Production $production) => [
                    'id' => $production->id,
                    'slug' => $production->slug,
                    'name' => $production->name,
                    'fantasy' => $production->fantasy,
                    'description' => $production->description,
                    'city' => $production->city,
                    'uf' => $production->uf,
                    'country' => $production->country,
                    'logo' => $production->logo,
                    'background' => $production->background,
                ])
                ->values(),
            'related_artists' => $this->artists($entry)
                ->map(fn (Artist $artist) => [
                    'id' => $artist->id,
                    'slug' => $artist->slug,
                    'stage_name' => $artist->stage_name,
                    'short_bio' => $artist->short_bio,
                    'city' => $artist->city,
                    'uf' => $artist->uf,
                    'genres' => $artist->genres,
                    'photo' => $artist->photo,
                    'cover' => $artist->cover,
                    'verification_status' => $artist->verification_status,
                ])
                ->values(),
            'related_items' => $this->items($entry)
                ->map(fn (Item $item) => [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'name' => $item->name,
                    'description' => $item->description,
                    'category' => $item->category,
                    'type' => $item->type,
                    'price' => $item->price,
                    'image_url' => $item->image_url,
                    'files' => $item->files
                        ->filter(fn ($file) => $file->isPublic() && ($file->type === 'image' || str_starts_with((string) $file->mime_type, 'image/')))
                        ->map(fn ($file) => [
                            'uuid' => $file->uuid,
                            'type' => $file->type,
                            'mime_type' => $file->mime_type,
                            'public_url' => $file->public_url,
                            'width' => $file->width,
                            'height' => $file->height,
                            'is_primary' => (bool) $file->is_primary,
                        ])
                        ->values(),
                    'establishment' => $item->establishment,
                ])
                ->values(),
        ];
    }

    public function items(ContentEntry $entry, int $limit = 6): Collection
    {
        $terms = $this->terms($entry);

        $base = fn () => Item::query()
            ->with([
                'files',
                'establishment' => fn ($establishments) => $establishments->select(['id', 'name', 'fantasy', 'slug', 'city', 'uf', 'logo', 'background']),
            ])
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $establishments) use ($entry) {
                $establishments->where('is_cancelled', false)->where('is_published', true);
                if ($entry->application_id) {
                    $establishments->forApplication((int) $entry->application_id);
                }
            });

        $query = $base();
        $this->applySearchTerms($query, $terms, ['name', 'category', 'subcategory', 'description', 'brand']);

        $items = $query
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($items->isEmpty() && $terms->isNotEmpty()) {
            $items = $base()
                ->orderByDesc('is_featured')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();
        }

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));

        return $items;
    }

    public function events(ContentEntry $entry, int $limit = 8): Collection
    {
        $terms = $this->terms($entry);

        $base = fn () => Event::query()
            ->select([
                'id', 'app_id', 'production_id', 'title', 'slug', 'description', 'category', 'image',
                'start_date', 'end_date', 'venue', 'city', 'uf', 'country', 'is_featured',
            ])
            ->with(['production:id,name,fantasy,slug,logo,background,city,uf'])
            ->publiclyVisible()
            ->when($entry->application_id, fn (Builder $query) => $query->where('app_id', $entry->application_id))
            ->where(fn (Builder $dates) => $dates->whereNull('end_date')->orWhere('end_date', '>=', now()->subHours(6)));

        $query = $base();
        $this->applySearchTerms($query, $terms, ['title', 'description', 'category', 'venue', 'city', 'country']);

        $events = $query
            ->orderByDesc('is_featured')
            ->orderBy('start_date')
            ->limit($limit)
            ->get();

        if ($events->isEmpty() && $terms->isNotEmpty()) {
            $events = $base()
                ->orderByDesc('is_featured')
                ->orderBy('start_date')
                ->limit($limit)
                ->get();
        }

        return $events;
    }

    public function productions(ContentEntry $entry, int $limit = 8): Collection
    {
        $terms = $this->terms($entry);

        $base = fn () => Production::query()
            ->select(['id', 'app_id', 'name', 'fantasy', 'slug', 'description', 'city', 'uf', 'country', 'logo', 'background', 'is_featured'])
            ->where('is_published', true)
            ->where(fn (Builder $status) => $status->where('is_cancelled', false)->orWhereNull('is_cancelled'))
            ->when($entry->application_id, fn (Builder $query) => $query->where('app_id', $entry->application_id));

        $query = $base();
        $this->applySearchTerms($query, $terms, ['name', 'fantasy', 'description', 'city', 'country']);

        $productions = $query
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($productions->isEmpty() && $terms->isNotEmpty()) {
            $productions = $base()
                ->orderByDesc('is_featured')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();
        }

        return $productions;
    }

    public function artists(ContentEntry $entry, int $limit = 8): Collection
    {
        $terms = $this->terms($entry);

        $base = fn () => Artist::query()
            ->select(['id', 'app_id', 'slug', 'stage_name', 'short_bio', 'city', 'uf', 'genres', 'photo', 'cover', 'verification_status'])
            ->where('is_active', true)
            ->where('is_published', true)
            ->when($entry->application_id, fn (Builder $query) => $query->where('app_id', $entry->application_id));

        $query = $base();
        $this->applySearchTerms($query, $terms, ['stage_name', 'short_bio', 'bio', 'city']);

        $artists = $query
            ->orderByDesc('verified_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($artists->isEmpty() && $terms->isNotEmpty()) {
            $artists = $base()
                ->orderByDesc('verified_at')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();
        }

        return $artists;
    }

    private function terms(ContentEntry $entry): Collection
    {
        return collect(array_merge(
            $entry->tags ?: [],
            [$entry->category, $entry->cluster, $entry->search_intent],
            is_array($entry->related) ? ($entry->related['keywords'] ?? []) : []
        ))
            ->filter()
            ->flatMap(fn ($value) => preg_split('/[^\pL\pN]+/u', Str::lower((string) $value)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => mb_strlen($value) >= 4)
            ->reject(fn ($value) => in_array($value, [
                'para', 'como', 'sobre', 'empresa', 'empresas', 'tecnologia',
                'evento', 'eventos', 'guia', 'dicas', 'melhores', 'brasil',
            ], true))
            ->unique()
            ->take(12)
            ->values();
    }

    private function applySearchTerms(Builder $query, Collection $terms, array $columns): void
    {
        if ($terms->isEmpty() || $columns === []) {
            return;
        }

        $query->where(function (Builder $search) use ($terms, $columns) {
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                foreach ($columns as $column) {
                    $search->orWhereRaw('LOWER(' . $column . ') LIKE ?', [$like]);
                }
            }
        });
    }
}
