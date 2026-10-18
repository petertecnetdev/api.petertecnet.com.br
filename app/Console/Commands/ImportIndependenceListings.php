<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Application;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ImportIndependenceListings extends Command
{
    protected $signature = 'independence:import-listings
        {manifest : Arquivo JSON com as fichas revisadas}
        {--reviewer= : ID do moderador que revisou o lote}
        {--limit= : Quantidade de eventos para o piloto (1 a 100)}
        {--apply : Gravar; sem esta opção apenas validar e listar}';

    protected $description = 'Importa informações públicas sem transferir propriedade nem vender ingressos.';

    public function handle(ApplicationAdminService $admins): int
    {
        try {
            $path = (string) $this->argument('manifest');
            $path = str_starts_with($path, '/') ? $path : base_path($path);
            if (! is_file($path)) {
                throw new RuntimeException('Arquivo não encontrado.');
            }
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->validateManifest($data);

            $app = Application::query()->where('slug', $data['application_slug'])->firstOrFail();
            $publisher = User::query()->where('email', $data['publisher_identity'])->firstOrFail();
            $reviewer = User::query()->find((int) $this->option('reviewer'));
            if (! $reviewer || ! $admins->hasPermission($reviewer, (int) $app->id, 'moderation.manage')
                || ! $admins->hasPermission($publisher, (int) $app->id, 'moderation.manage')) {
                throw new RuntimeException('Revisor ou publicador sem permissão de moderação para esta aplicação.');
            }
            foreach ([
                'events' => ['ownership_status', 'verification_status', 'source_url', 'published_by_user_id', 'commerce_authorized_at'],
                'establishments' => ['ownership_status', 'verification_status', 'source_url', 'published_by_user_id', 'commerce_authorized_at'],
                'tickets' => ['curatorial_information_only', 'curatorial_source_url', 'curatorial_price_observed_at', 'curatorial_bundle_size'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        throw new RuntimeException("Migração obrigatória pendente: {$table}.{$column}");
                    }
                }
            }
            $limit = $this->option('limit');
            if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100)) {
                throw new RuntimeException('Limite permitido: de 1 a 100.');
            }
            $events = array_slice($data['events'], 0, $limit === null ? null : (int) $limit);
            $organizations = collect($data['organizers'])->keyBy('key');
            $artists = collect($data['artists'])->keyBy('key');
            foreach ($events as $event) {
                if (! $organizations->has($event['organizer_key'])) {
                    throw new RuntimeException("Produção ausente: {$event['organizer_key']}");
                }
                foreach ($event['artists'] as $key) {
                    if (! $artists->has($key)) {
                        throw new RuntimeException("Artista ausente: {$key}");
                    }
                }
            }

            $stats = ['productions' => 0, 'events' => 0, 'tickets' => 0, 'artists' => 0,
                'existing' => 0, 'skipped' => []];
            if (! $this->option('apply')) {
                foreach ($events as $e) {
                    $this->line("REVISADO: {$e['title']} ({$e['start_at']}), ".count($e['ticket_information']).' lotes informativos');
                }
                $this->info('DRY-RUN: nada foi gravado; use --apply após homologação.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($app, $publisher, $reviewer, $events, $organizations, $artists, $data, &$stats): void {
                // Lock serializa importações concorrentes; source_url garante idempotência.
                DB::table('applications')->where('id', $app->id)->lockForUpdate()->first();
                $now = now()->toDateTimeString();
                foreach ($events as $candidate) {
                    $start = new DateTimeImmutable($candidate['start_at']);
                    $end = new DateTimeImmutable($candidate['end_at']);
                    if ($start->getTimestamp() <= time()) {
                        $stats['skipped'][] = ['event' => $candidate['key'], 'reason' => 'past'];
                        continue;
                    }
                    $eventExists = DB::table('events')->where('app_id', $app->id)
                        ->where(function ($q) use ($candidate, $start) {
                            $q->where('source_url', $candidate['source_url'])
                                ->orWhere(function ($eq) use ($candidate, $start) {
                                    $eq->where('title', $candidate['title'])
                                        ->where('start_date', $start->format('Y-m-d H:i:s'));
                                });
                        })->exists();
                    if ($eventExists) {
                        $stats['existing']++;
                        continue;
                    }

                    $org = $organizations->get($candidate['organizer_key']);
                    $production = DB::table('establishments')->where('app_id', $app->id)
                        ->where('category', 'production')
                        ->where(function ($q) use ($org) {
                            $q->where('source_url', $org['source_url'])->orWhere('name', $org['name']);
                        })->first();
                    if ($production && $production->ownership_status !== 'unclaimed') {
                        // Uma ficha reivindicada/legada nunca pode ser reclassificada.
                        $stats['skipped'][] = ['event' => $candidate['key'], 'reason' => 'ownership_review'];
                        continue;
                    }
                    $productionId = $production ? (int) $production->id : DB::table('establishments')->insertGetId([
                        'app_id' => $app->id, 'app_slug' => $app->slug,
                        'name' => $org['name'], 'fantasy' => $org['name'],
                        'slug' => $this->uniqueSlug('establishments', $org['key'].'-bh'),
                        'type' => 'production', 'category' => 'production', 'establishment_type' => 'production',
                        'description' => $org['description'].' Ficha informativa publicada pela curadoria; titularidade não verificada.',
                        'city' => $org['city'], 'uf' => $org['state'], 'country' => 'BR',
                        'user_id' => $publisher->id, 'created_by' => $publisher->id,
                        'updated_by' => $publisher->id,
                        'ownership_status' => 'unclaimed', 'verification_status' => 'public_source_only',
                        'listing_source' => 'independence_curated', 'source_url' => $org['source_url'],
                        'last_verified_at' => $now, 'published_by_user_id' => $reviewer->id,
                        'commerce_authorized_at' => null,
                        'is_published' => true, 'is_approved' => true, 'is_cancelled' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if (! $production) {
                        $stats['productions']++;
                        DB::table('application_establishment')->updateOrInsert(
                            ['application_id' => $app->id, 'establishment_id' => $productionId],
                            ['is_primary' => true, 'created_at' => $now, 'updated_at' => $now]
                        );
                    }
                    // Fonte e auditoria ficam em colunas próprias, nunca em links promocionais no texto.
                    $meta = ['curation' => ['batch' => $data['candidate_batch'],
                        'checked_on' => $data['origin_verified_at'], 'claimed' => false,
                        'sales_enabled' => false, 'image_authorized' => false]];
                    $eventId = DB::table('events')->insertGetId([
                        'app_id' => $app->id, 'app_slug' => $app->slug,
                        'production_id' => $productionId, 'created_by_user_id' => $publisher->id,
                        'title' => $candidate['title'],
                        'slug' => $this->uniqueSlug('events', $candidate['key'].'-bh'),
                        'description' => $candidate['description'].
                            "\n\nFicha informativa da curadoria Cutinapp. O organizador ainda não reivindicou o evento. Ingressos e valores são apenas informativos; não há vendas, reservas ou emissão pela Cutinapp.",
                        'category' => 'shows', 'event_format' => 'in_person',
                        'venue' => $candidate['venue'], 'address' => $candidate['address'],
                        'city' => $candidate['city'], 'uf' => $candidate['state'],
                        'state' => $candidate['state'], 'country' => 'BR',
                        'start_date' => $start->format('Y-m-d H:i:s'),
                        'end_date' => $end->format('Y-m-d H:i:s'),
                        'organizer_name' => $org['name'],
                        'extra_info' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'ownership_status' => 'unclaimed', 'verification_status' => 'public_source_only',
                        'listing_source' => 'independence_curated', 'source_url' => $candidate['source_url'],
                        'last_verified_at' => $now, 'published_by_user_id' => $reviewer->id,
                        'commerce_authorized_at' => null,
                        'is_published' => true, 'is_approved' => true, 'is_cancelled' => false, 'is_private' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $stats['events']++;
                    foreach ($candidate['ticket_information'] as $offer) {
                        DB::table('tickets')->insert([
                            'app_id' => $app->id, 'app_slug' => $app->slug,
                            'event_id' => $eventId, 'name' => $offer['label'],
                            'type' => 'paid', 'ticket_type' => 'standard',
                            'price' => $offer['price_brl'], 'quantity' => 0,
                            'description' => 'Preço anunciado para referência, sujeito a alteração. Venda indisponível na Cutinapp.',
                            'curatorial_information_only' => true,
                            'curatorial_source_url' => $candidate['source_url'],
                            'curatorial_price_observed_at' => $offer['price_as_of'],
                            'curatorial_bundle_size' => $offer['bundle_size'],
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $stats['tickets']++;
                    }
                    foreach ($candidate['artists'] as $key) {
                        $artist = $artists->get($key);
                        $found = DB::table('artists')->where('app_id', $app->id)->whereNull('deleted_at')
                            ->where('stage_name', $artist['stage_name'])->first(['id']);
                        $artistId = $found ? (int) $found->id : DB::table('artists')->insertGetId([
                            'app_id' => $app->id, 'user_id' => null, 'created_by_user_id' => $publisher->id,
                            'stage_name' => $artist['stage_name'],
                            'slug' => $this->uniqueSlug('artists', $artist['key']),
                            'short_bio' => $artist['short_bio'],
                            'bio' => $artist['short_bio'].' Artista identificado em programação divulgada; perfil ainda não reivindicado.',
                            'city' => 'Belo Horizonte', 'uf' => 'MG',
                            'verification_status' => 'unverified', 'is_active' => true, 'is_published' => true,
                            'origin_type' => 'public_event_curation', 'origin_label' => 'Evento divulgado publicamente',
                            'metadata' => json_encode(['source_url' => $artist['source_url'], 'curation_batch' => $data['candidate_batch']]),
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        if (! $found) {
                            $stats['artists']++;
                        }
                        DB::table('event_artist')->updateOrInsert(
                            ['event_id' => $eventId, 'artist_id' => $artistId],
                            ['app_id' => $app->id, 'participation_type' => 'show', 'status' => 'confirmed',
                                'created_at' => $now, 'updated_at' => $now]
                        );
                    }
                }
            }, 3);

            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function validateManifest(mixed $data): void
    {
        if (! is_array($data) || ($data['application_slug'] ?? null) !== 'cutinapp'
            || ($data['publish_policy'] ?? null) !== 'information_only_unclaimed'
            || ($data['status'] ?? null) !== 'reviewed_sources_ready_for_import_gate'
            || ! is_array($data['events'] ?? null) || ! is_array($data['organizers'] ?? null)
            || ! is_array($data['artists'] ?? null) || count($data['events']) === 0) {
            throw new RuntimeException('Manifesto inválido: revisão editorial pendente.');
        }
        $sources = [];
        foreach ($data['organizers'] as $org) {
            if (empty($org['key']) || empty($org['name']) || mb_strlen((string) ($org['description'] ?? '')) < 40
                || ! empty($org['claimed']) || empty($org['source_url'])) {
                throw new RuntimeException('Organização sem dados públicos suficientes.');
            }
        }
        foreach ($data['events'] as $event) {
            if (empty($event['key']) || empty($event['organizer_key']) || empty($event['venue'])
                || empty($event['address']) || mb_strlen((string) ($event['description'] ?? '')) < 50
                || ($event['city'] ?? null) !== 'Belo Horizonte' || ($event['state'] ?? null) !== 'MG'
                || ($event['listing_status'] ?? null) !== 'unclaimed' || $event['commerce_enabled'] !== false
                || new DateTimeImmutable($event['end_at']) <= new DateTimeImmutable($event['start_at'])) {
                throw new RuntimeException('Evento sem dados confiáveis, cidade, endereço ou bloqueio.');
            }
            $url = (string) ($event['source_url'] ?? '');
            $host = parse_url($url, PHP_URL_HOST);
            if (! str_starts_with($url, 'https://') || ! is_string($host) || $host === ''
                || $host === 'localhost' || str_ends_with($host, '.internal')
                || filter_var($host, FILTER_VALIDATE_IP) || strlen($url) > 2048
                || isset($sources[$url])) {
                throw new RuntimeException('Fonte repetida ou endereço externo inválido.');
            }
            $sources[$url] = true;
            foreach ($event['ticket_information'] as $ticket) {
                if ($ticket['informational_only'] !== true || $ticket['sales_enabled'] !== false
                    || (float) $ticket['price_brl'] < 0 || (int) $ticket['bundle_size'] < 1
                    || $ticket['source_url'] !== $url) {
                    throw new RuntimeException('Preço sem fonte ou ingresso com venda habilitada.');
                }
            }
        }
    }

    private function uniqueSlug(string $table, string $candidate): string
    {
        $base = Str::slug($candidate);
        $slug = $base;
        $i = 2;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }
}
