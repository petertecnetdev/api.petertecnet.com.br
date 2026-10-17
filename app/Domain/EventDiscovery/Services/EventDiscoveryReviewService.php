<?php

namespace App\Domain\EventDiscovery\Services;

use App\Models\Establishment;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EventDiscoveryReviewService
{
    public function index(int $appId, ?string $status, int $perPage): array
    {
        $queue = DB::table('event_discovery_candidates')
            ->where('app_id', $appId)
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderBy('start_at')
            ->paginate($perPage);

        $counts = DB::table('event_discovery_candidates')->where('app_id', $appId)
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        return ['data' => $queue, 'counts' => $counts];
    }

    /** @return array{candidate_id:int,status:string,event_id?:int} */
    public function review(
        int $appId,
        string $appSlug,
        int $reviewerId,
        int $candidateId,
        array $input,
    ): array {
        return DB::transaction(function () use ($appId, $appSlug, $reviewerId, $candidateId, $input): array {
            $candidate = DB::table('event_discovery_candidates')
                ->where('app_id', $appId)->lockForUpdate()->find($candidateId);
            abort_unless($candidate, 404, 'Candidato não encontrado.');

            abort_unless(in_array($candidate->status, ['pending', 'possible_duplicate'], true), 409,
                'O candidato já foi revisado.');

            if ($input['decision'] === 'reject') {
                DB::table('event_discovery_candidates')->where('id', $candidateId)->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $reviewerId,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
                return ['candidate_id' => $candidateId, 'status' => 'rejected'];
            }

            $production = Establishment::query()
                ->where('app_id', $appId)
                ->where('ownership_status', 'unclaimed')
                ->find((int) $input['production_id']);

            if (! $production) {
                throw ValidationException::withMessages([
                    'production_id' => 'Selecione uma produção curatorial não reivindicada desta aplicação.',
                ]);
            }

            $address = trim((string) ($input['address'] ?? $candidate->address ?? ''));
            if ($address === '') {
                throw ValidationException::withMessages([
                    'address' => 'Confirme o endereço completo antes de cadastrar o rascunho.',
                ]);
            }

            if (CarbonImmutable::parse($candidate->start_at)->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['start_at' => 'Evento no passado não pode ser cadastrado.']);
            }

            if (Event::query()
                ->where('app_id', $appId)
                ->where('start_date', $candidate->start_at)
                ->where('title', $candidate->title)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'candidate' => 'Já existe um evento com mesmo título, horário e aplicação.',
                ]);
            }

            $event = new Event();
            $event->forceFill([
                'app_id' => $appId,
                'app_slug' => $appSlug,
                'production_id' => $production->id,
                'created_by_user_id' => $reviewerId,
                'title' => $candidate->title,
                'slug' => Str::slug($candidate->title).'-'.Str::lower(Str::random(8)),
                'description' => (string) ($candidate->description ?: $candidate->title),
                'start_date' => $candidate->start_at,
                'end_date' => $candidate->end_at ?: CarbonImmutable::parse($candidate->start_at)->addHours(3),
                'event_format' => 'in_person',
                'venue' => $candidate->venue,
                'address' => $address,
                'city' => $candidate->city,
                'uf' => $candidate->uf,
                'state' => $candidate->uf,
                'country' => 'Brasil',
                'organizer_name' => $candidate->organizer,
                'is_published' => false,
                'is_approved' => false,
                'is_private' => false,
                'is_cancelled' => false,
                'ownership_status' => 'unclaimed',
                'verification_status' => 'public_source_unverified',
                'listing_source' => 'authorized_feed',
                'source_url' => $candidate->source_url,
                'last_verified_at' => null,
            ]);
            $event->save();

            DB::table('event_discovery_candidates')->where('id', $candidateId)->update([
                'event_id' => $event->id,
                'status' => 'drafted',
                'reviewed_by_user_id' => $reviewerId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['candidate_id' => $candidateId, 'status' => 'drafted', 'event_id' => (int) $event->id];
        }, 3);
    }
}
