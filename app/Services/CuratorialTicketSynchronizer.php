<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CuratorialTicketSynchronizer
{
    /**
     * Lotes da curadoria sao informativos: nunca inventar precos ou estoque.
     * Nao interfere com ingressos criados pelo produtor.
     */
    public function sync(bool $apply = false): array
    {
        $appId = DB::table('applications')->where('slug', (string) config('curation.application_slug'))->value('id');
        $ownerEmail = config('curation.owner_email');
        $ownerId = DB::table('users')->where('email', $ownerEmail)->value('id');
        if (! $appId || ! $ownerId) {
            throw new RuntimeException('Aplicacao ou responsavel pela curadoria inexistente.');
        }

        $hasNativeCuratorialFlag = Schema::hasColumn('tickets', 'curatorial_information_only');

        $events = DB::table('events as e')
            ->join('establishments as p', 'p.id', '=', 'e.production_id')
            ->where('e.app_id', $appId)
            ->where('p.app_id', $appId)
            ->where('p.user_id', $ownerId)
            ->where('p.category', 'production')
            ->where('e.extra_info', 'like', '%curation%')
            ->select('e.id', 'e.app_id', 'e.app_slug', 'e.extra_info')
            ->orderBy('e.id')
            ->get();

        $report = [
            'mode' => $apply ? 'apply' : 'audit',
            'events_scanned' => $events->count(),
            'events_eligible' => 0,
            'events_skipped_ownership' => 0,
            'events_skipped_existing' => 0,
            'tickets_to_create' => 0,
            'tickets_created' => 0,
            'options_with_confirmed_price' => 0,
            'options_without_price' => 0,
        ];

        foreach ($events as $event) {
            $info = json_decode((string) $event->extra_info, true);
            $curation = is_array($info) ? ($info['curation'] ?? null) : null;
            if (! is_array($curation)
                || ($curation['owner_status'] ?? null) !== 'unclaimed'
                || ($curation['sale_mode'] ?? null) !== 'information_only'
                || ($curation['ticket_checkout_enabled'] ?? null) !== false) {
                $report['events_skipped_ownership']++;
                continue;
            }

            $report['events_eligible']++;
            $options = self::normalizeOptions($info['informational_ticket_options'] ?? null);
            foreach ($options as $option) {
                $report[$option['price'] === null ? 'options_without_price' : 'options_with_confirmed_price']++;
            }

            // Bloqueio transacional por evento: execucoes concorrentes nao duplicam lotes.
            DB::transaction(function () use ($event, $options, $apply, $hasNativeCuratorialFlag, &$report) {
                $current = DB::table('events')->where('id', $event->id)->lockForUpdate()->first(['extra_info']);
                $metadata = json_decode((string) ($current->extra_info ?? ''), true);
                $curation = is_array($metadata) ? ($metadata['curation'] ?? []) : [];
                if (($curation['owner_status'] ?? null) !== 'unclaimed'
                    || ($curation['sale_mode'] ?? null) !== 'information_only'
                    || ($curation['ticket_checkout_enabled'] ?? null) !== false) {
                    $report['events_skipped_ownership']++;
                    return;
                }

                $existing = DB::table('tickets')
                    ->where('app_id', $event->app_id)
                    ->where('event_id', $event->id)
                    ->get($hasNativeCuratorialFlag ? ['id', 'extras', 'curatorial_information_only'] : ['id', 'extras']);
                // Ingressos de produtores nunca sao substituidos por fichas curatoriais.
                if ($existing->contains(fn ($ticket) => ! ((bool) ($ticket->curatorial_information_only ?? false) || (json_decode((string) $ticket->extras, true)['curation_information_only'] ?? false)))) {
                    $report['events_skipped_existing']++;
                    return;
                }

                $knownKeys = $existing->mapWithKeys(function ($ticket) {
                    $extras = json_decode((string) $ticket->extras, true) ?: [];
                    return isset($extras['curation_option_key']) ? [$extras['curation_option_key'] => true] : [];
                });

                foreach ($options as $option) {
                    $key = hash('sha256', mb_strtolower($option['name'], 'UTF-8') . '|' . ($option['price'] ?? 'unknown'));
                    if ($knownKeys->has($key)) {
                        continue;
                    }

                    $report['tickets_to_create']++;
                    if (! $apply) {
                        continue;
                    }

                    $payload = [
                        'app_id' => $event->app_id,
                        'app_slug' => $event->app_slug ?: (string) config('curation.application_slug'),
                        'event_id' => $event->id,
                        'name' => $option['name'],
                        'ticket_type' => $option['price'] === 0.0 ? 'courtesy' : 'standard',
                        'type' => $option['price'] === null ? 'informational' : ($option['price'] > 0 ? 'paid' : 'courtesy'),
                        'price' => $option['price'],
                        'quantity' => 0,
                        'limit_date' => null,
                        'description' => 'Ficha informativa da curadoria. Compra, retirada e emissao bloqueadas ate autorizacao comercial.',
                        'extras' => json_encode([
                            'curation_information_only' => true,
                            'curation_option_key' => $key,
                            'price_known' => $option['price'] !== null,
                            'source' => 'event.extra_info.informational_ticket_options',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($hasNativeCuratorialFlag) {
                        $payload['curatorial_information_only'] = true;
                        $payload['curatorial_source_url'] = null;
                        $payload['curatorial_price_observed_at'] = null;
                        $payload['curatorial_bundle_size'] = 1;
                    }
                    DB::table('tickets')->insert($payload);
                    $report['tickets_created']++;
                }
            }, 3);
        }

        return $report;
    }

    /** @return array<int, array{name: string, price: ?float}> */
    public static function normalizeOptions(mixed $options): array
    {
        $valid = [];
        if (is_array($options)) {
            foreach ($options as $option) {
                if (! is_array($option) || ! is_string($option['name'] ?? null)) {
                    continue;
                }
                $name = trim($option['name']);
                $price = $option['price'] ?? null;
                if ($name === '' || mb_strlen($name) > 255 || ! is_numeric($price)
                    || (float) $price < 0 || (float) $price > 999999.99) {
                    continue;
                }
                $valid[hash('sha256', mb_strtolower($name, 'UTF-8') . '|' . round((float) $price, 2))] = [
                    'name' => $name, 'price' => round((float) $price, 2),
                ];
            }
        }

        return array_values($valid) ?: [['name' => 'Ingressos — valor a confirmar', 'price' => null]];
    }
}
