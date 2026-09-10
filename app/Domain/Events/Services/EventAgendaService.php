<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\EventAgendaSetting;
use App\Models\EventSchedule;
use App\Models\Production;
use App\Models\User;
use App\Services\ProducerAgreementService;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;

final class EventAgendaService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
        private readonly EventAgendaMaintenanceService $maintenance,
    ) {}

    public function index(int $productionId, User $user): array
    {
        $production = $this->ownedProduction($productionId, $user);
        $setting = $this->setting($production);

        $schedules = EventSchedule::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->with('sourceEvent')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('title')
            ->get()
            ->map(fn (EventSchedule $schedule) => $this->presentSchedule($schedule))
            ->values();

        $availableEvents = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('is_cancelled', false)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->map(fn (Event $event) => $this->presentSourceEvent($event))
            ->values();

        return [
            'agenda' => [
                'production_id' => $production->id,
                'is_active' => (bool) $setting->is_active,
                'generation_weeks' => (int) ($setting->generation_weeks ?: 1),
                'max_future_occurrences' => 7 * (int) ($setting->generation_weeks ?: 1),
            ],
            'schedules' => $schedules,
            'available_events' => $availableEvents,
        ];
    }

    public function setAgendaStatus(int $productionId, User $user, bool $isActive): array
    {
        $production = $this->ownedProduction($productionId, $user);
        $setting = $this->setting($production);

        if ($isActive) {
            $this->ensureProducerAgreement($production);
        }

        $setting->update(['is_active' => $isActive]);

        $generation = $isActive
            ? $this->maintenance->replenishProduction(
                (int) $setting->app_id,
                (int) $setting->production_id,
                (int) ($setting->generation_weeks ?: 1),
                $this->context->slug(),
            )
            : ['created_count' => 0, 'existing_count' => 0, 'retired_count' => 0];

        return [
            'message' => $setting->is_active ? 'Agenda semanal ativada.' : 'Agenda semanal pausada.',
            'agenda' => [
                'production_id' => $production->id,
                'is_active' => (bool) $setting->is_active,
                'generation_weeks' => (int) ($setting->generation_weeks ?: 1),
                'max_future_occurrences' => 7 * (int) ($setting->generation_weeks ?: 1),
            ],
            'generation' => $generation,
        ];
    }

    public function updateSettings(int $productionId, User $user, int $generationWeeks): array
    {
        $production = $this->ownedProduction($productionId, $user);
        $setting = $this->setting($production);
        $setting->update(['generation_weeks' => max(1, min(3, $generationWeeks))]);

        $generation = $setting->is_active
            ? $this->maintenance->replenishProduction(
                (int) $setting->app_id,
                (int) $setting->production_id,
                (int) $setting->generation_weeks,
                $this->context->slug(),
            )
            : ['created_count' => 0, 'existing_count' => 0, 'retired_count' => 0];

        return [
            'message' => 'Horizonte da agenda semanal atualizado.',
            'agenda' => [
                'production_id' => $production->id,
                'is_active' => (bool) $setting->is_active,
                'generation_weeks' => (int) $setting->generation_weeks,
                'max_future_occurrences' => 7 * (int) $setting->generation_weeks,
            ],
            'generation' => $generation,
        ];
    }

    public function store(int $productionId, User $user, array $input, mixed $imageFile = null): array
    {
        $production = $this->ownedProduction($productionId, $user);

        if (array_key_exists('event_id', $input)) {
            return $this->linkExistingEvent($production, $input);
        }

        $data = $this->validateSchedule($input, true, null, $imageFile);
        $image = ! empty($data['image']) ? $this->storeScheduleImage($data['image']) : null;
        unset($data['image']);

        $schedule = EventSchedule::create([
            ...$data,
            'app_id' => $this->context->id(),
            'production_id' => $production->id,
            'image' => $image,
            'event_format' => $data['event_format'] ?? 'in_person',
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        $this->setting($production);

        return [
            'message' => 'Evento adicionado à agenda semanal.',
            'schedule' => $this->presentSchedule($schedule),
        ];
    }

    public function update(int $scheduleId, User $user, array $input, mixed $imageFile = null): array
    {
        $schedule = $this->ownedSchedule($scheduleId, $user);

        if (array_key_exists('event_id', $input)) {
            $production = $schedule->production;
            $input['day_of_week'] = $input['day_of_week'] ?? $schedule->day_of_week;
            return $this->linkExistingEvent($production, $input, $schedule);
        }

        $data = $this->validateSchedule($input, false, $schedule, $imageFile);
        $newImage = $data['image'] ?? null;
        unset($data['image']);

        if ($newImage) {
            $oldImage = $schedule->image;
            $data['image'] = $this->storeScheduleImage($newImage);
            $schedule->update($data);
            $this->deleteScheduleImage($oldImage);
        } else {
            $schedule->update($data);
        }

        return [
            'message' => 'Evento da agenda atualizado.',
            'schedule' => $this->presentSchedule($schedule->fresh('sourceEvent')),
        ];
    }

    public function setItemStatus(int $scheduleId, User $user, bool $isActive): array
    {
        $schedule = $this->ownedSchedule($scheduleId, $user);
        $schedule->update(['is_active' => $isActive]);

        return [
            'message' => $schedule->is_active ? 'Evento fixo ativado.' : 'Evento fixo pausado.',
            'schedule' => $this->presentSchedule($schedule->fresh('sourceEvent')),
        ];
    }

    public function destroy(int $scheduleId, User $user): array
    {
        $schedule = $this->ownedSchedule($scheduleId, $user);
        $retired = $this->maintenance->retireRemovedSchedule($schedule);
        $image = $schedule->image;
        $schedule->delete();
        $this->deleteScheduleImage($image);

        return [
            'message' => 'Evento removido da agenda semanal. O evento original continua preservado.',
            'retired_count' => $retired,
        ];
    }

    public function generate(int $scheduleId, User $user): array
    {
        $schedule = $this->ownedSchedule($scheduleId, $user);
        $production = $schedule->production;
        $this->ensureAgendaCanGenerate($production, $schedule);
        $this->ensureProducerAgreement($production);
        $setting = $this->setting($production);

        $generation = $this->maintenance->replenishSchedule(
            $schedule,
            (int) ($setting->generation_weeks ?: 1),
            $this->context->slug(),
        );

        return [
            'body' => [
                'message' => $generation['created_count'] > 0
                    ? $generation['created_count'].' ocorrência(s) reposta(s) dentro do horizonte semanal.'
                    : 'O horizonte deste dia já está preenchido.',
                ...$generation,
            ],
            'status' => $generation['created_count'] > 0 ? 201 : 200,
        ];
    }

    public function generateUpcoming(int $productionId, User $user): array
    {
        $production = $this->ownedProduction($productionId, $user);
        $setting = $this->setting($production);
        abort_unless($setting->is_active, 422, 'Ative a agenda semanal antes de gerar os próximos eventos.');
        $this->ensureProducerAgreement($production);

        $generation = $this->maintenance->replenishProduction(
            (int) $setting->app_id,
            (int) $setting->production_id,
            (int) ($setting->generation_weeks ?: 1),
            $this->context->slug(),
        );

        return [
            'message' => $generation['created_count'] > 0
                ? $generation['created_count'].' ocorrência(s) reposta(s) dentro do horizonte semanal.'
                : 'A agenda já está preenchida até o horizonte escolhido.',
            ...$generation,
        ];
    }

    private function linkExistingEvent(Production $production, array $input, ?EventSchedule $targetSchedule = null): array
    {
        $this->ensureProducerAgreement($production);

        $data = Validator::make($input, [
            'event_id' => 'required|integer|min:1',
            'day_of_week' => 'required|integer|between:0,6',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('is_cancelled', false)
            ->findOrFail((int) $data['event_id']);

        $payload = $this->schedulePayloadFromEvent(
            $event,
            (int) $data['day_of_week'],
            array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true
        );

        $previousSourceEventId = $targetSchedule?->source_event_id;

        $schedule = DB::transaction(function () use ($production, $payload, $targetSchedule) {
            $schedule = $targetSchedule;

            if (! $schedule) {
                $schedule = EventSchedule::query()
                    ->where('app_id', $this->context->id())
                    ->where('production_id', $production->id)
                    ->where('day_of_week', $payload['day_of_week'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }

            if ($schedule) {
                $oldImage = $schedule->image;
                $schedule->update($payload);
                if ($oldImage !== $payload['image']) {
                    $this->deleteScheduleImage($oldImage);
                }

                EventSchedule::query()
                    ->where('app_id', $this->context->id())
                    ->where('production_id', $production->id)
                    ->where('day_of_week', $payload['day_of_week'])
                    ->where('id', '!=', $schedule->id)
                    ->get()
                    ->each(function (EventSchedule $duplicate) {
                        $oldImage = $duplicate->image;
                        $duplicate->delete();
                        $this->deleteScheduleImage($oldImage);
                    });
            } else {
                $schedule = EventSchedule::create([
                    ...$payload,
                    'app_id' => $this->context->id(),
                    'production_id' => $production->id,
                ]);
            }

            return $schedule->fresh('sourceEvent');
        });

        $this->ensureProducerAgreement($production);
        $setting = $this->setting($production);
        $retiredFromTemplateChange = $this->maintenance->reconcileTemplateChange(
            $schedule,
            $previousSourceEventId ? (int) $previousSourceEventId : null,
        );
        $generation = $setting->is_active
            ? $this->maintenance->replenishSchedule(
                $schedule,
                (int) ($setting->generation_weeks ?: 1),
                $this->context->slug(),
            )
            : ['created_count' => 0, 'existing_count' => 0, 'retired_count' => 0, 'events' => []];

        $generation['retired_count'] = (int) ($generation['retired_count'] ?? 0) + $retiredFromTemplateChange;

        return [
            'message' => 'Evento-modelo definido para este dia da agenda semanal.',
            'schedule' => $this->presentSchedule($schedule->fresh('sourceEvent')),
            'generation' => $generation,
        ];
    }

    private function schedulePayloadFromEvent(Event $event, int $dayOfWeek, bool $isActive): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::parse((string) $event->start_date, $timezone);
        $end = $event->end_date
            ? Carbon::parse((string) $event->end_date, $timezone)
            : $start->copy()->addHours(3);

        if ($end->equalTo($start)) {
            $end = $start->copy()->addHours(3);
        }

        return [
            'source_event_id' => (int) $event->id,
            'title' => (string) ($event->title ?: 'Evento'),
            'description' => (string) ($event->description ?: $event->title ?: 'Evento da agenda semanal'),
            'category' => $event->category,
            'image' => $event->image,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'venue' => $event->venue,
            'address' => $event->address,
            'google_maps_url' => $event->google_maps_url,
            'city' => $event->city,
            'uf' => $event->uf ?: $event->state,
            'cep' => $event->cep,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'max_attendees' => $event->max_attendees,
            'contact_email' => $event->contact_email,
            'contact_phone' => $event->contact_phone,
            'is_private' => (bool) $event->is_private,
            'event_format' => $event->event_format ?: 'in_person',
            'online_url' => $event->online_url,
            'is_active' => $isActive,
        ];
    }

    private function validateSchedule(array $input, bool $creating, ?EventSchedule $schedule = null, mixed $imageFile = null): array
    {
        if ($imageFile !== null) {
            $input['image'] = $imageFile;
        }

        $required = $creating ? 'required|' : 'sometimes|';
        $data = Validator::make($input, [
            'title' => $required.'string|min:2|max:255',
            'description' => $required.'string|max:50000',
            'category' => 'sometimes|nullable|string|max:120',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'day_of_week' => $required.'integer|between:0,6',
            'start_time' => $required.'date_format:H:i',
            'end_time' => $required.'date_format:H:i',
            'venue' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:500',
            'google_maps_url' => 'sometimes|nullable|url:http,https|max:2048',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'cep' => 'sometimes|nullable|string|max:20',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'max_attendees' => 'sometimes|nullable|integer|min:1|max:1000000',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_private' => 'sometimes|boolean',
            'event_format' => 'sometimes|nullable|in:in_person,online,hybrid',
            'online_url' => 'sometimes|nullable|url:http,https|max:2048',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        if (array_key_exists('uf', $data) && $data['uf'] !== null) {
            $data['uf'] = strtoupper(trim((string) $data['uf']));
        }

        $format = (string) ($data['event_format'] ?? $schedule?->event_format ?? 'in_person');
        if ($format === '') {
            $format = 'in_person';
            $data['event_format'] = $format;
        }

        $effective = static function (string $field) use ($data, $schedule) {
            return array_key_exists($field, $data) ? $data[$field] : $schedule?->{$field};
        };

        $errors = [];
        $physical = in_array($format, ['in_person', 'hybrid'], true);
        if ($physical && ! trim((string) $effective('address'))) {
            $errors['address'][] = 'Informe o endereço do evento presencial.';
        }
        if ($physical && ! trim((string) $effective('city'))) {
            $errors['city'][] = 'Informe a cidade do evento presencial.';
        }
        if ($physical && strlen(trim((string) $effective('uf'))) !== 2) {
            $errors['uf'][] = 'Informe a UF do evento presencial.';
        }
        if (in_array($format, ['online', 'hybrid'], true) && ! trim((string) $effective('online_url'))) {
            $errors['online_url'][] = 'Informe a URL de acesso da parte online do evento.';
        }

        $startTime = (string) $effective('start_time');
        $endTime = (string) $effective('end_time');
        if ($startTime !== '' && $startTime === $endTime) {
            $errors['end_time'][] = 'O horário de término deve ser diferente do horário de início.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function ensureAgendaCanGenerate(Production $production, EventSchedule $schedule): void
    {
        $setting = $this->setting($production);
        abort_unless($setting->is_active, 422, 'A agenda semanal desta produção está pausada.');
        abort_unless($schedule->is_active, 422, 'Este evento fixo está pausado na agenda.');
    }

    private function ensureProducerAgreement(Production $production): void
    {
        if (! (bool) $this->context->option('events.requires_producer_agreement', false)) {
            return;
        }

        $signed = DB::table('contract_acceptances')
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('contract_version', $this->agreements->version())
            ->exists();

        abort_unless($signed, 428, 'Antes de criar eventos desta organização, leia e assine o termo de adesão.');
    }

    private function setting(Production $production): EventAgendaSetting
    {
        return EventAgendaSetting::firstOrCreate([
            'app_id' => $this->context->id(),
            'production_id' => $production->id,
        ], [
            'is_active' => true,
            'generation_weeks' => 1,
        ]);
    }

    private function ownedProduction(int $id, User $user): Production
    {
        $production = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($id);

        abort_unless(
            $user->hasProfile('Administrador') || (int) $production->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar esta organização.'
        );

        return $production;
    }

    private function ownedSchedule(int $id, User $user): EventSchedule
    {
        $schedule = EventSchedule::query()
            ->where('app_id', $this->context->id())
            ->with(['production', 'sourceEvent'])
            ->findOrFail($id);

        abort_unless($schedule->production, 404, 'Produção da agenda não encontrada.');
        abort_unless(
            $user->hasProfile('Administrador') || (int) $schedule->production->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar este evento da agenda.'
        );

        return $schedule;
    }

    private function presentSchedule(EventSchedule $schedule): array
    {
        $schedule->loadMissing('sourceEvent');
        $data = $schedule->toArray();
        $data['start_time'] = substr((string) $schedule->start_time, 0, 5);
        $data['end_time'] = substr((string) $schedule->end_time, 0, 5);
        $data['is_active'] = (bool) $schedule->is_active;
        $data['is_private'] = (bool) $schedule->is_private;
        $data['source_event'] = $schedule->sourceEvent
            ? $this->presentSourceEvent($schedule->sourceEvent)
            : null;

        return $data;
    }

    private function presentSourceEvent(Event $event): array
    {
        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'slug' => $event->slug,
            'image' => $event->image,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'venue' => $event->venue,
            'city' => $event->city,
            'uf' => $event->uf ?: $event->state,
            'is_published' => (bool) $event->is_published,
            'is_cancelled' => (bool) $event->is_cancelled,
        ];
    }

    private function storeScheduleImage($file): string
    {
        $directory = 'images/apps/'.$this->context->slug().'/event-agenda';
        $path = $directory.'/'.Str::uuid().'.webp';
        $image = Image::make($file)
            ->orientate()
            ->resize(1920, 1080, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->encode('webp', 86);

        Storage::disk('public')->put($path, (string) $image);
        return $path;
    }

    private function deleteScheduleImage(?string $path): void
    {
        $prefix = 'images/apps/'.$this->context->slug().'/event-agenda/';
        if ($path && str_starts_with($path, $prefix)) {
            Storage::disk('public')->delete($path);
        }
    }
}
