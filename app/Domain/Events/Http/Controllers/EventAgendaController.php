<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAgendaSetting;
use App\Models\EventSchedule;
use App\Models\Production;
use App\Models\User;
use App\Services\ProducerAgreementService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;

final class EventAgendaController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerAgreementService $agreements,
    ) {}

    public function index(Request $request, int $productionId)
    {
        $production = $this->ownedProduction($productionId, $request->user());
        $setting = $this->setting($production);

        $schedules = EventSchedule::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('title')
            ->get()
            ->map(fn (EventSchedule $schedule) => $this->presentSchedule($schedule))
            ->values();

        return response()->json([
            'agenda' => [
                'production_id' => $production->id,
                'is_active' => (bool) $setting->is_active,
            ],
            'schedules' => $schedules,
        ]);
    }

    public function setAgendaStatus(Request $request, int $productionId)
    {
        $production = $this->ownedProduction($productionId, $request->user());
        $data = $request->validate(['is_active' => 'required|boolean']);
        $setting = $this->setting($production);
        $setting->update(['is_active' => (bool) $data['is_active']]);

        return response()->json([
            'message' => $setting->is_active ? 'Agenda semanal ativada.' : 'Agenda semanal pausada.',
            'agenda' => [
                'production_id' => $production->id,
                'is_active' => (bool) $setting->is_active,
            ],
        ]);
    }

    public function store(Request $request, int $productionId)
    {
        $production = $this->ownedProduction($productionId, $request->user());
        $data = $this->validateSchedule($request, true);
        $image = $request->hasFile('image') ? $this->storeScheduleImage($request->file('image')) : null;
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

        return response()->json([
            'message' => 'Evento adicionado à agenda semanal.',
            'schedule' => $this->presentSchedule($schedule),
        ], 201);
    }

    public function update(Request $request, int $scheduleId)
    {
        $schedule = $this->ownedSchedule($scheduleId, $request->user());
        $data = $this->validateSchedule($request, false, $schedule);
        unset($data['image']);

        if ($request->hasFile('image')) {
            $oldImage = $schedule->image;
            $data['image'] = $this->storeScheduleImage($request->file('image'));
            $schedule->update($data);
            $this->deleteScheduleImage($oldImage);
        } else {
            $schedule->update($data);
        }

        return response()->json([
            'message' => 'Evento da agenda atualizado.',
            'schedule' => $this->presentSchedule($schedule->fresh()),
        ]);
    }

    public function setItemStatus(Request $request, int $scheduleId)
    {
        $schedule = $this->ownedSchedule($scheduleId, $request->user());
        $data = $request->validate(['is_active' => 'required|boolean']);
        $schedule->update(['is_active' => (bool) $data['is_active']]);

        return response()->json([
            'message' => $schedule->is_active ? 'Evento fixo ativado.' : 'Evento fixo pausado.',
            'schedule' => $this->presentSchedule($schedule),
        ]);
    }

    public function destroy(Request $request, int $scheduleId)
    {
        $schedule = $this->ownedSchedule($scheduleId, $request->user());
        $image = $schedule->image;
        $schedule->delete();
        $this->deleteScheduleImage($image);

        return response()->json([
            'message' => 'Evento removido da agenda. Eventos já criados continuam preservados.',
        ]);
    }

    public function generate(Request $request, int $scheduleId)
    {
        $schedule = $this->ownedSchedule($scheduleId, $request->user());
        $production = $schedule->production;
        $this->ensureAgendaCanGenerate($production, $schedule);
        $this->ensureProducerAgreement($production);

        [$event, $created] = $this->generateScheduleOccurrence($schedule->id);

        return response()->json([
            'message' => $created
                ? 'Próxima ocorrência criada como rascunho.'
                : 'Esta ocorrência já havia sido criada. Abrimos o evento existente.',
            'created' => $created,
            'event' => $event,
        ], $created ? 201 : 200);
    }

    public function generateUpcoming(Request $request, int $productionId)
    {
        $production = $this->ownedProduction($productionId, $request->user());
        $setting = $this->setting($production);
        abort_unless($setting->is_active, 422, 'Ative a agenda semanal antes de gerar os próximos eventos.');
        $this->ensureProducerAgreement($production);

        $schedules = EventSchedule::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $production->id)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        abort_if($schedules->isEmpty(), 422, 'Não há eventos ativos na agenda desta produção.');

        $events = [];
        $createdCount = 0;
        $existingCount = 0;

        foreach ($schedules as $schedule) {
            [$event, $created] = $this->generateScheduleOccurrence($schedule->id);
            $events[] = $event;
            $created ? $createdCount++ : $existingCount++;
        }

        return response()->json([
            'message' => $createdCount > 0
                ? $createdCount.' evento(s) criado(s) como rascunho.'
                : 'As próximas ocorrências já estavam criadas.',
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'events' => $events,
        ]);
    }

    private function validateSchedule(Request $request, bool $creating, ?EventSchedule $schedule = null): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        $data = $request->validate([
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
        ]);

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

    private function generateScheduleOccurrence(int $scheduleId): array
    {
        return DB::transaction(function () use ($scheduleId) {
            $schedule = EventSchedule::query()
                ->where('app_id', $this->context->id())
                ->with('production')
                ->lockForUpdate()
                ->findOrFail($scheduleId);

            $occurrence = $this->nextOccurrence($schedule);
            $date = $occurrence['date'];

            $existing = Event::query()
                ->where('app_id', $this->context->id())
                ->where('event_schedule_id', $schedule->id)
                ->whereDate('event_schedule_occurrence_date', $date->toDateString())
                ->first();

            if ($existing) {
                return [$existing->load('production:id,app_id,name,slug,user_id,app_slug'), false];
            }

            $image = $this->copyScheduleImageToEvent($schedule->image);

            try {
                $event = Event::create([
                    'app_id' => $this->context->id(),
                    'app_slug' => $this->context->slug(),
                    'production_id' => $schedule->production_id,
                    'title' => $schedule->title,
                    'description' => $schedule->description,
                    'category' => $schedule->category,
                    'image' => $image,
                    'event_format' => $schedule->event_format ?: 'in_person',
                    'address' => $schedule->address,
                    'google_maps_url' => $schedule->google_maps_url,
                    'start_date' => $occurrence['start'],
                    'end_date' => $occurrence['end'],
                    'venue' => $schedule->venue,
                    'city' => $schedule->city,
                    'uf' => $schedule->uf,
                    'state' => $schedule->uf,
                    'cep' => $schedule->cep,
                    'latitude' => $schedule->latitude,
                    'longitude' => $schedule->longitude,
                    'max_attendees' => $schedule->max_attendees,
                    'contact_email' => $schedule->contact_email,
                    'contact_phone' => $schedule->contact_phone,
                    'is_private' => (bool) $schedule->is_private,
                    'online_url' => $schedule->online_url,
                    'slug' => $this->uniqueSlug($schedule->title),
                    'is_published' => false,
                    'is_cancelled' => false,
                ]);
            } catch (\Throwable $exception) {
                if ($image) {
                    Storage::disk('public')->delete($image);
                }
                throw $exception;
            }

            $event->forceFill([
                'event_schedule_id' => $schedule->id,
                'event_schedule_occurrence_date' => $date->toDateString(),
            ])->saveQuietly();

            return [$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'), true];
        });
    }

    private function nextOccurrence(EventSchedule $schedule): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $daysAhead = ((int) $schedule->day_of_week - (int) $now->dayOfWeek + 7) % 7;
        $date = $now->copy()->startOfDay()->addDays($daysAhead);
        $start = $date->copy()->setTimeFromTimeString(substr((string) $schedule->start_time, 0, 8));

        if ($start->lte($now)) {
            $date->addWeek();
            $start->addWeek();
        }

        $end = $date->copy()->setTimeFromTimeString(substr((string) $schedule->end_time, 0, 8));
        if ($end->lte($start)) {
            $end->addDay();
        }

        return [
            'date' => $date,
            'start' => $start,
            'end' => $end,
        ];
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
            ->with('production')
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
        $data = $schedule->toArray();
        $data['start_time'] = substr((string) $schedule->start_time, 0, 5);
        $data['end_time'] = substr((string) $schedule->end_time, 0, 5);
        $data['is_active'] = (bool) $schedule->is_active;
        $data['is_private'] = (bool) $schedule->is_private;

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evento';
        $slug = $base;
        $counter = 2;

        while (Event::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
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

    private function copyScheduleImageToEvent(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $target = 'images/apps/'.$this->context->slug().'/events/'.Str::uuid().'.webp';
        return Storage::disk('public')->copy($path, $target) ? $target : null;
    }

    private function deleteScheduleImage(?string $path): void
    {
        $prefix = 'images/apps/'.$this->context->slug().'/event-agenda/';
        if ($path && str_starts_with($path, $prefix)) {
            Storage::disk('public')->delete($path);
        }
    }
}
