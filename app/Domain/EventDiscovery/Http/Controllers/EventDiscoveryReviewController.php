<?php

namespace App\Domain\EventDiscovery\Http\Controllers;

use App\Domain\Locations\Services\MunicipalityService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EventDiscoveryReviewController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', 'in:pending,possible_duplicate,rejected,drafted'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $appId = $this->context->id();
        $queue = DB::table('event_discovery_candidates')
            ->where('app_id', $appId)
            ->when($input['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('start_at')
            ->paginate((int) ($input['per_page'] ?? 25));

        $counts = DB::table('event_discovery_candidates')->where('app_id', $appId)
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        return response()->json(['success' => true, 'data' => $queue, 'counts' => $counts]);
    }

    public function review(Request $request, int $candidateId): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:reject,create_draft'],
            'production_id' => ['required_if:decision,create_draft', 'nullable', 'integer', 'min:1'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $appId = $this->context->id();
        $result = DB::transaction(function () use ($request, $candidateId, $appId, $input): array {
            $candidate = DB::table('event_discovery_candidates')
                ->where('app_id', $appId)->lockForUpdate()->find($candidateId);
            abort_unless($candidate, 404, 'Candidato não encontrado.');

            abort_unless(in_array($candidate->status, ['pending', 'possible_duplicate'], true), 409,
                'O candidato já foi revisado.');

            if ($input['decision'] === 'reject') {
                DB::table('event_discovery_candidates')->where('id', $candidateId)->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $request->user('api')->getKey(),
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

            if (\Carbon\CarbonImmutable::parse($candidate->start_at)->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['start_at' => 'Evento no passado não pode ser cadastrado.']);
            }

            $sameEvent = Event::query()
                ->where('app_id', $appId)
                ->where('start_date', $candidate->start_at)
                ->where('title', $candidate->title)
                ->exists();
            if ($sameEvent) {
                throw ValidationException::withMessages([
                    'candidate' => 'Já existe um evento com mesmo título, horário e aplicação.',
                ]);
            }

            $event = new Event();
            $event->forceFill([
                'app_id' => $appId,
                'app_slug' => $this->context->slug(),
                'production_id' => $production->id,
                'created_by_user_id' => $request->user('api')->getKey(),
                'title' => $candidate->title,
                'slug' => Str::slug($candidate->title).'-'.Str::lower(Str::random(8)),
                'description' => (string) ($candidate->description ?: $candidate->title),
                'start_date' => $candidate->start_at,
                'end_date' => $candidate->end_at ?: \Carbon\CarbonImmutable::parse($candidate->start_at)->addHours(3),
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
            // Event aplica as regras já existentes de cidade e data no model.
            $event->save();

            DB::table('event_discovery_candidates')->where('id', $candidateId)->update([
                'event_id' => $event->id,
                'status' => 'drafted',
                'reviewed_by_user_id' => $request->user('api')->getKey(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['candidate_id' => $candidateId, 'status' => 'drafted', 'event_id' => $event->id];
        }, 3);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
