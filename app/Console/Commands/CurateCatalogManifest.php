<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CurateCatalogManifest extends Command
{
    protected $signature = 'catalog:curate
        {manifest : Caminho do manifesto JSON, absoluto ou relativo à raiz do projeto}
        {--dry-run : Apenas exibe o plano, sem gravar}
        {--archive-orphans : Arquiva itens do mesmo owner/app apontando para estabelecimentos inexistentes}';

    protected $description = 'Aplica de forma idempotente uma curadoria de catálogo a partir de um manifesto versionado.';

    public function handle(): int
    {
        $path = $this->argument('manifest');
        $path = Str::startsWith($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("Manifesto não encontrado: {$path}");
            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->validateManifest($manifest);

        $application = Application::query()->where('slug', $manifest['application_slug'])->firstOrFail();
        $owner = User::query()->where('email', $manifest['owner_email'])->firstOrFail();
        $establishment = Establishment::query()
            ->where('slug', $manifest['establishment_slug'])
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $desiredSlugs = collect($manifest['items'])
            ->map(fn (array $item) => $item['slug'] ?? Str::slug($item['name']))
            ->filter()
            ->values();

        $current = Item::withTrashed()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->get();

        $toArchive = $current->filter(
            fn (Item $item) => ! $desiredSlugs->contains($item->slug)
        );

        $this->newLine();
        $this->info('Curadoria de catálogo');
        $this->line("Aplicação: {$application->name} ({$application->slug})");
        $this->line("Estabelecimento: {$establishment->name} (#{$establishment->id})");
        $this->line('Itens desejados: ' . count($manifest['items']));
        $this->line('Itens atuais fora do catálogo curado: ' . $toArchive->count());

        if ($this->option('dry-run')) {
            foreach ($manifest['items'] as $definition) {
                $slug = $definition['slug'] ?? Str::slug($definition['name']);
                $exists = $current->firstWhere('slug', $slug);
                $this->line(sprintf(
                    '  %s %s',
                    $exists ? 'ATUALIZAR' : 'CRIAR',
                    $definition['name']
                ));
            }
            foreach ($toArchive as $item) {
                $this->line("  ARQUIVAR {$item->name} (#{$item->id})");
            }
            if ($this->option('archive-orphans')) {
                $this->line('  ARQUIVAR itens órfãos do mesmo owner/app');
            }
            return self::SUCCESS;
        }

        if (! Schema::hasColumn('items', 'catalog_profile')) {
            throw new RuntimeException(
                'A migration de experiência de catálogo ainda não foi executada. Rode php artisan migrate antes da curadoria.'
            );
        }

        DB::transaction(function () use ($manifest, $application, $owner, $establishment, $desiredSlugs) {
            $pivot = DB::table('application_establishment')
                ->where('application_id', $application->id)
                ->where('establishment_id', $establishment->id);

            if ($pivot->exists()) {
                $pivot->update([
                    'is_primary' => (bool) ($manifest['make_primary'] ?? false),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('application_establishment')->insert([
                    'application_id' => $application->id,
                    'establishment_id' => $establishment->id,
                    'is_primary' => (bool) ($manifest['make_primary'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($manifest['make_primary'] ?? false) {
                DB::table('application_establishment')
                    ->where('application_id', $application->id)
                    ->where('establishment_id', '!=', $establishment->id)
                    ->update(['is_primary' => false, 'updated_at' => now()]);
            }

            foreach ($manifest['items'] as $index => $definition) {
                $slug = $definition['slug'] ?? Str::slug($definition['name']);
                $profile = $definition['catalog_profile'] ?? [];
                $payload = [
                    'user_id' => $owner->id,
                    'app_id' => $application->id,
                    'name' => $definition['name'],
                    'slug' => $slug,
                    'type' => $definition['type'] ?? 'service',
                    'description' => $definition['description'] ?? null,
                    'short_description' => $definition['short_description'] ?? null,
                    'price' => $definition['price'] ?? 0,
                    'pricing_model' => $definition['pricing_model'] ?? 'quote',
                    'price_min' => $definition['price_min'] ?? null,
                    'price_max' => $definition['price_max'] ?? null,
                    'setup_price' => $definition['setup_price'] ?? null,
                    'recurring_price' => $definition['recurring_price'] ?? null,
                    'billing_interval' => $definition['billing_interval'] ?? null,
                    'stock' => 0,
                    'status' => true,
                    'category' => $definition['category'] ?? 'Soluções Digitais',
                    'subcategory' => $definition['subcategory'] ?? null,
                    'brand' => $definition['brand'] ?? 'Peter Tecnet',
                    'is_featured' => (bool) ($definition['is_featured'] ?? false),
                    'sort_order' => $definition['sort_order'] ?? (($index + 1) * 10),
                    'is_quote_enabled' => (bool) ($definition['is_quote_enabled'] ?? true),
                    'is_checkout_enabled' => (bool) ($definition['is_checkout_enabled'] ?? false),
                    'entity_id' => $establishment->id,
                    'entity_name' => 'establishment',
                    'tags' => $definition['tags'] ?? [],
                    'catalog_profile' => $profile,
                    'seo_title' => $definition['seo_title'] ?? ($definition['name'] . ' | Peter Tecnet'),
                    'seo_description' => $definition['seo_description'] ?? ($definition['short_description'] ?? null),
                    'canonical_url' => $definition['canonical_url'] ?? null,
                    'og_image' => $definition['og_image'] ?? null,
                    'archived_at' => null,
                    'deleted_at' => null,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ];

                $item = Item::withTrashed()
                    ->where('entity_name', 'establishment')
                    ->where('entity_id', $establishment->id)
                    ->where('slug', $slug)
                    ->first();

                if (! $item) {
                    $item = new Item();
                } elseif ($item->trashed()) {
                    $item->restore();
                }

                $item->forceFill($payload)->save();
                $item->recordCatalogVersion($owner->id, 'manifest-curation');
            }

            Item::query()
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->whereNotIn('slug', $desiredSlugs->all())
                ->update([
                    'status' => false,
                    'is_featured' => false,
                    'archived_at' => now(),
                    'updated_by' => $owner->id,
                    'updated_at' => now(),
                ]);

            if ($this->option('archive-orphans')) {
                Item::query()
                    ->where('user_id', $owner->id)
                    ->where('app_id', $application->id)
                    ->where('entity_name', 'establishment')
                    ->whereNotExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('establishments')
                            ->whereColumn('establishments.id', 'items.entity_id')
                            ->whereNull('establishments.deleted_at');
                    })
                    ->update([
                        'status' => false,
                        'is_featured' => false,
                        'archived_at' => now(),
                        'updated_by' => $owner->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->info('Curadoria aplicada com sucesso.');
        return self::SUCCESS;
    }

    private function validateManifest(array $manifest): void
    {
        foreach (['application_slug', 'owner_email', 'establishment_slug', 'items'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new RuntimeException("Campo obrigatório ausente no manifesto: {$field}");
            }
        }

        if (! is_array($manifest['items']) || count($manifest['items']) === 0) {
            throw new RuntimeException('O manifesto deve possuir ao menos um item.');
        }

        $slugs = [];
        foreach ($manifest['items'] as $item) {
            if (empty($item['name'])) {
                throw new RuntimeException('Todo item do manifesto precisa de name.');
            }
            $slug = $item['slug'] ?? Str::slug($item['name']);
            if (isset($slugs[$slug])) {
                throw new RuntimeException("Slug duplicado no manifesto: {$slug}");
            }
            $slugs[$slug] = true;
        }
    }
}
