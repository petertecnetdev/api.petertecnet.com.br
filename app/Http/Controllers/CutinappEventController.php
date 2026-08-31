<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;

class CutinappEventController extends Controller
{
    private const APP = 'cutinapp';

    public function publicEvents(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $appId = $this->applicationId();
        $query = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('end_date', '>', now())
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->withCount(['tickets' => fn ($ticketQuery) => $ticketQuery
                ->where('app_id', $appId)
                ->where('price', 0)])
            ->orderBy('start_date');

        if (! empty($data['city'])) {
            $query->where('city', $data['city']);
        }

        if (! empty($data['q'])) {
            $term = '%' . trim($data['q']) . '%';
            $query->where(function ($nested) use ($term) {
                $nested->where('title', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('venue', 'like', $term)
                    ->orWhereHas('production', fn ($production) => $production->where('name', 'like', $term));
            });
        }

        return response()->json(['events' => $query->paginate($data['per_page'] ?? 24)]);
    }

    public function publicEvent(string $slug)
    {
        $appId = $this->applicationId();
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('end_date', '>', now())
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->firstOrFail();

        $tickets = Ticket::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('price', 0)
            ->withCount('passes')
            ->orderBy('created_at')
            ->get()
            ->map(function (Ticket $ticket) {
                $remaining = max(0, (int) $ticket->quantity - (int) $ticket->passes_count);
                $expired = $ticket->limit_date && now()->greaterThan($ticket->limit_date);
                $ticket->setAttribute('remaining', $remaining);
                $ticket->setAttribute('available', $remaining > 0 && ! $expired);
                $ticket->setAttribute('expired', (bool) $expired);
                return $ticket;
            });

        return response()->json(['event' => $event, 'tickets' => $tickets]);
    }

    public function mine(Request $request)
    {
        $appId = $this->applicationId();
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json([
            'events' => Event::query()
                ->where('app_id', $appId)
                ->where('app_slug', self::APP)
                ->whereHas('production', fn ($query) => $query
                    ->where('app_id', $appId)
                    ->where('user_id', Auth::id()))
                ->with('production:id,app_id,name,slug,user_id,app_slug')
                ->withCount(['tickets' => fn ($query) => $query->where('app_id', $appId)])
                ->orderByDesc('start_date')
                ->paginate($perPage),
        ]);
    }

    public function show(int $id)
    {
        $appId = $this->applicationId();
        $event = $this->ownedEvent($id);
        $event->load('production:id,app_id,name,slug,user_id,app_slug');
        $event->loadCount(['tickets' => fn ($query) => $query->where('app_id', $appId)]);

        return response()->json(['event' => $event]);
    }

    public function store(Request $request)
    {
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(true), $this->messages(), $this->attributes());
        $production = $this->ownedProduction((int) $data['production_id']);
        $this->validateDates($data, null);

        if (empty($data['city']) && $production->city) {
            $data['city'] = $production->city;
        }
        if (empty($data['uf']) && $production->uf) {
            $data['uf'] = $production->uf;
        }

        $appId = $this->applicationId();
        $data['app_id'] = $appId;
        $data['app_slug'] = self::APP;
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['is_published'] = false;
        $data['is_cancelled'] = false;
        unset($data['image']);

        $event = Event::create($data);
        if ($request->hasFile('image')) {
            $event->image = $this->storeImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento criado como rascunho. Configure a cortesia e publique quando estiver pronto.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $event = $this->ownedEvent($id);
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(false), $this->messages(), $this->attributes());

        if (isset($data['production_id'])) {
            $this->ownedProduction((int) $data['production_id']);
        }

        $this->validateDates($data, $event);

        if (! empty($data['title']) && $data['title'] !== $event->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $event->id);
        }

        unset($data['image'], $data['app_id'], $data['app_slug'], $data['is_published'], $data['is_cancelled']);
        $event->update($data);

        if ($request->hasFile('image')) {
            if ($event->image && str_starts_with($event->image, 'images/cutinapp/events/')) {
                Storage::disk('public')->delete($event->image);
            }
            $event->image = $this->storeImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento atualizado com sucesso.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ]);
    }

    public function publish(int $id)
    {
        $event = $this->ownedEvent($id);
        abort_if($event->is_cancelled, 422, 'Um evento cancelado não pode ser publicado.');
        abort_if(! $event->end_date || $event->end_date->lte(now()), 422, 'Um evento já encerrado não pode ser publicado.');
        abort_if(! $event->start_date || $event->start_date->lte(now()), 422, 'A data de início precisa estar no futuro para publicar o evento.');

        $hasAvailableCourtesy = Ticket::query()
            ->where('app_id', $this->applicationId())
            ->where('event_id', $event->id)
            ->where('price', 0)
            ->where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('limit_date')->orWhere('limit_date', '>', now());
            })
            ->exists();

        abort_unless($hasAvailableCourtesy, 422, 'Crie ao menos uma cortesia disponível antes de publicar o evento.');
        $event->forceFill(['is_published' => true])->save();

        return response()->json([
            'message' => 'Evento publicado. A página pública já está disponível.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ]);
    }

    public function unpublish(int $id)
    {
        $event = $this->ownedEvent($id);
        $event->forceFill(['is_published' => false])->save();

        return response()->json([
            'message' => 'Evento retirado da publicação. Os ingressos já emitidos foram preservados.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ]);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'production_id' => $required . 'integer|exists:productions,id',
            'title' => $required . 'string|min:2|max:255',
            'description' => $required . 'string|max:50000',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'address' => $required . 'string|max:500',
            'google_maps_url' => 'sometimes|nullable|url:http,https|max:2048',
            'start_date' => $required . 'date',
            'end_date' => $required . 'date',
            'venue' => 'sometimes|nullable|string|max:255',
            'uf' => 'sometimes|nullable|string|size:2',
            'city' => 'sometimes|nullable|string|max:120',
            'cep' => 'sometimes|nullable|string|max:20',
            'max_attendees' => 'sometimes|nullable|integer|min:1|max:1000000',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_private' => 'sometimes|boolean',
        ];
    }

    private function normalizeInput(Request $request): void
    {
        $merge = [];
        if ($request->has('uf')) {
            $merge['uf'] = strtoupper(trim((string) $request->input('uf')));
        }
        if ($request->has('google_maps_url')) {
            $url = trim((string) $request->input('google_maps_url'));
            $merge['google_maps_url'] = $url === '' ? null : $url;
        }
        foreach (['start_date', 'end_date'] as $field) {
            if ($request->filled($field)) {
                $merge[$field] = Carbon::parse((string) $request->input($field), config('app.timezone'))->format('Y-m-d H:i:s');
            }
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function validateDates(array $data, ?Event $event): void
    {
        $startValue = $data['start_date'] ?? $event?->start_date;
        $endValue = $data['end_date'] ?? $event?->end_date;
        if (! $startValue || ! $endValue) {
            return;
        }

        $start = Carbon::parse($startValue, config('app.timezone'));
        $end = Carbon::parse($endValue, config('app.timezone'));
        $errors = [];

        if (($event === null || array_key_exists('start_date', $data)) && $start->lt(now()->subMinute())) {
            $errors['start_date'][] = 'O início do evento não pode ficar no passado.';
        }
        if (! $end->gt($start)) {
            $errors['end_date'][] = 'O término do evento precisa ser posterior ao início.';
        }
        if ($start->diffInDays($end) > 30) {
            $errors['end_date'][] = 'A duração do evento não pode ultrapassar 30 dias.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ownedProduction(int $id): Production
    {
        $production = Production::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->findOrFail($id);

        abort_unless(
            Auth::user()?->hasProfile('Administrador') || (int) $production->user_id === (int) Auth::id(),
            403,
            'Você não pode gerenciar esta produção.'
        );

        return $production;
    }

    private function ownedEvent(int $id): Event
    {
        $event = Event::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->with('production')
            ->findOrFail($id);

        abort_unless(
            $event->production && (int) $event->production->app_id === $this->applicationId(),
            404,
            'Evento não encontrado na Cutinapp.'
        );
        abort_unless(
            Auth::user()?->hasProfile('Administrador') || (int) $event->production->user_id === (int) Auth::id(),
            403,
            'Você não pode gerenciar este evento.'
        );

        return $event;
    }

    private function applicationId(): int
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();
        abort_unless($application, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $application->id;
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'evento';
        $slug = $base;
        $counter = 2;
        while (Event::query()->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }

    private function storeImage($file): string
    {
        $directory = 'images/cutinapp/events';
        $name = Str::uuid() . '.webp';
        $path = $directory . '/' . $name;
        $image = Image::make($file)->orientate()->resize(1920, 1080, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86);
        Storage::disk('public')->put($path, (string) $image);
        return $path;
    }

    private function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'min' => ':attribute está abaixo do tamanho mínimo permitido.',
            'integer' => ':attribute precisa ser um número inteiro.',
            'exists' => ':attribute não foi encontrado ou não está mais disponível.',
            'date' => 'Informe uma data válida em :attribute.',
            'email' => 'Informe um e-mail válido.',
            'url' => 'Informe uma URL completa iniciando com https://.',
            'image' => ':attribute precisa ser uma imagem válida.',
            'mimes' => ':attribute deve ser JPG, PNG ou WebP.',
            'max' => ':attribute ultrapassou o limite permitido.',
            'size' => ':attribute precisa ter :size caracteres.',
        ];
    }

    private function attributes(): array
    {
        return [
            'production_id' => 'a produção',
            'title' => 'o nome do evento',
            'description' => 'a descrição',
            'image' => 'a imagem',
            'address' => 'o endereço',
            'google_maps_url' => 'o link do Google Maps',
            'start_date' => 'a data de início',
            'end_date' => 'a data de término',
            'venue' => 'o local',
            'uf' => 'a UF',
            'city' => 'a cidade',
            'max_attendees' => 'a capacidade',
            'contact_email' => 'o e-mail de contato',
            'contact_phone' => 'o telefone de contato',
        ];
    }
}
