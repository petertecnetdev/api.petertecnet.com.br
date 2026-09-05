<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EventDuplicationService
{
    public function duplicateForApplicationUser(
        int $eventId,
        string $date,
        int $appId,
        ?string $appSlug,
        int $userId,
        bool $isAdministrator = false,
        array $overrides = [],
    ): Event {
        $source = Event::query()
            ->where('app_id', $appId)
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->findOrFail($eventId);

        abort_unless(
            $source->production && (int) $source->production->app_id === $appId,
            404,
            'Evento não encontrado neste contexto.'
        );

        abort_unless(
            $isAdministrator || (int) $source->production->user_id === $userId,
            403,
            'Você não pode duplicar este evento.'
        );

        return $this->duplicate($source, $date, $appId, $appSlug, $overrides);
    }

    public function duplicate(Event $source, string $date, int $appId, ?string $appSlug = null, array $overrides = []): Event
    {
        $source->loadMissing([
            'artists:id,app_id,slug,stage_name',
            'tickets' => fn ($query) => $query->where('app_id', $appId),
        ]);

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        if (! $source->start_date || ! $source->end_date) {
            throw ValidationException::withMessages([
                'date' => ['O evento original precisa ter início e término definidos antes de ser duplicado.'],
            ]);
        }

        $sourceStart = Carbon::parse($source->start_date, $timezone);
        $sourceEnd = Carbon::parse($source->end_date, $timezone);

        if ($date === $sourceStart->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'date' => ['Escolha uma data diferente da data do evento original.'],
            ]);
        }

        $targetStart = ! empty($overrides['start_date'])
            ? Carbon::parse($overrides['start_date'], $timezone)
            : Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $date.' '.$sourceStart->format('H:i:s'),
                $timezone
            );

        if ($targetStart->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([
                'start_date' => ['A data do início precisa corresponder à nova data escolhida para a duplicação.'],
            ]);
        }

        if ($targetStart->lte(Carbon::now($timezone))) {
            throw ValidationException::withMessages([
                'date' => ['A nova data e horário precisam manter o início do evento no futuro.'],
            ]);
        }

        $durationSeconds = $sourceEnd->getTimestamp() - $sourceStart->getTimestamp();
        if ($durationSeconds <= 0) {
            throw ValidationException::withMessages([
                'date' => ['A duração do evento original é inválida. Revise o evento antes de duplicá-lo.'],
            ]);
        }

        $targetEnd = ! empty($overrides['end_date'])
            ? Carbon::parse($overrides['end_date'], $timezone)
            : $targetStart->copy()->addSeconds($durationSeconds);

        if ($targetEnd->lte($targetStart)) {
            throw ValidationException::withMessages([
                'end_date' => ['O término da cópia precisa ser posterior ao início.'],
            ]);
        }

        $editable = $this->editableOverrides($overrides);
        $deltaSeconds = $targetStart->getTimestamp() - $sourceStart->getTimestamp();
        $copiedImage = $this->copyImage($source->image);
        $resolvedAppSlug = $appSlug ?: $source->app_slug;
        $slugTitle = trim((string) ($editable['title'] ?? $source->title)) ?: $source->title;

        try {
            $duplicate = DB::transaction(function () use ($source, $targetStart, $targetEnd, $deltaSeconds, $copiedImage, $timezone, $appId, $resolvedAppSlug, $editable, $slugTitle) {
                $event = $source->replicate(['id', 'slug', 'created_at', 'updated_at']);
                $event->forceFill(array_merge($editable, [
                    'slug' => $this->uniqueSlug($slugTitle.' '.$targetStart->format('Y-m-d')),
                    'start_date' => $targetStart->format('Y-m-d H:i:s'),
                    'end_date' => $targetEnd->format('Y-m-d H:i:s'),
                    'image' => $copiedImage,
                    'app_id' => $appId,
                    'app_slug' => $resolvedAppSlug,
                    'is_published' => false,
                    'is_cancelled' => false,
                    'is_featured' => false,
                    'reviews' => [],
                    'rating' => null,
                    'remaining_tickets' => $source->tickets->sum('quantity') ?: null,
                ]));
                $event->save();

                foreach ($source->tickets as $ticket) {
                    $price = round((float) $ticket->price, 2);
                    $paid = $price > 0;
                    $type = trim((string) $ticket->type);
                    $ticketType = trim((string) $ticket->ticket_type);

                    Ticket::create([
                        'app_id' => $appId,
                        'app_slug' => $resolvedAppSlug,
                        'event_id' => $event->id,
                        'name' => $ticket->name,
                        'type' => $type !== '' ? $type : ($paid ? 'paid' : 'courtesy'),
                        'price' => $price,
                        'limit_date' => $this->shiftTicketDeadline($ticket->limit_date, $deltaSeconds, $targetStart, $timezone),
                        'ticket_type' => $ticketType !== '' ? $ticketType : ($paid ? 'standard' : 'courtesy'),
                        'quantity' => $ticket->quantity,
                        'description' => $ticket->description,
                    ]);
                }

                foreach ($source->artists as $artist) {
                    $scheduledAt = $artist->pivot?->scheduled_at
                        ? Carbon::parse($artist->pivot->scheduled_at, $timezone)->addSeconds($deltaSeconds)->format('Y-m-d H:i:s')
                        : null;

                    $event->artists()->attach($artist->id, [
                        'participation_type' => $artist->pivot?->participation_type,
                        'stage' => $artist->pivot?->stage,
                        'scheduled_at' => $scheduledAt,
                        'description' => $artist->pivot?->description,
                        'sort_order' => $artist->pivot?->sort_order,
                        'is_headliner' => (bool) $artist->pivot?->is_headliner,
                    ]);
                }

                return $event;
            });
        } catch (\Throwable $exception) {
            if ($copiedImage && $copiedImage !== $source->image) {
                Storage::disk('public')->delete($copiedImage);
            }
            throw $exception;
        }

        $duplicate->load(['production:id,app_id,name,slug,user_id,app_slug', 'artists:id,app_id,slug,stage_name']);
        $duplicate->loadCount(['tickets' => fn ($query) => $query->where('app_id', $appId)]);

        return $duplicate;
    }

    private function editableOverrides(array $overrides): array
    {
        $allowed = [
            'title',
            'description',
            'category',
            'event_format',
            'venue',
            'address',
            'google_maps_url',
            'city',
            'uf',
            'country',
            'online_platform',
            'online_url',
            'online_instructions',
            'max_attendees',
            'contact_email',
            'contact_phone',
            'is_private',
            'requires_approval',
            'approval_message',
        ];

        return array_intersect_key($overrides, array_flip($allowed));
    }

    private function shiftTicketDeadline($value, int $deltaSeconds, Carbon $targetStart, string $timezone): ?string
    {
        if (! $value) return null;

        $deadline = Carbon::parse($value, $timezone)->addSeconds($deltaSeconds);
        $minimumUsefulDeadline = Carbon::now($timezone)->addHour();
        if ($deadline->lte($minimumUsefulDeadline)) return null;
        if ($deadline->gt($targetStart)) $deadline = $targetStart->copy();

        return $deadline->format('Y-m-d H:i:s');
    }

    private function copyImage(?string $sourcePath): ?string
    {
        if (! $sourcePath || ! Storage::disk('public')->exists($sourcePath)) return $sourcePath;

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
        $directory = trim(dirname($sourcePath), './');
        $targetPath = ($directory !== '' ? $directory.'/' : '').Str::uuid().'.'.$extension;

        return Storage::disk('public')->copy($sourcePath, $targetPath) ? $targetPath : $sourcePath;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'evento';
        $slug = $base;
        $counter = 2;
        while (Event::query()->where('slug', $slug)->exists()) $slug = $base.'-'.$counter++;
        return $slug;
    }
}
