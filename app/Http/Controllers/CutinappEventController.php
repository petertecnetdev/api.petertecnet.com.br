<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventController extends Controller
{
    private const APP = 'cutinapp';

    public function publicEvents(Request $request)
    {
        return app(CutinappDiscoveryController::class)->events($request);
    }

    public function publicEvent(Request $request, string $slug)
    {
        $appId = $this->applicationId();
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->where('end_date', '>', now())
            ->with([
                'production:id,app_id,name,slug,user_id,app_slug,logo,background,description,city,uf,instagram_url,website_url',
                'artists' => fn ($q) => $q->where('cutinapp_artists.app_id', $appId)->where('cutinapp_artists.is_published', true)->orderByDesc('cutinapp_event_artist.is_headliner')->orderBy('cutinapp_event_artist.sort_order'),
            ])
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

        if ($viewer = $this->optionalRequestUser($request)) {
            $engagement = DB::table('cutinapp_event_engagements')
                ->where(['app_id' => $appId, 'user_id' => $viewer->id, 'event_id' => $event->id])
                ->first();
            $event->setAttribute('viewer_engagement', $engagement);
        }

        return response()->json(['event' => $event, 'tickets' => $tickets]);
    }

    public function mine(Request $request)
    {
        $user = $this->requestUser($request);
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'status' => 'nullable|in:draft,published,cancelled,upcoming,past',
            'production_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $appId = $this->applicationId();
        $query = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->whereHas('production', fn ($q) => $q->where('app_id', $appId)->where('user_id', $user->id))
            ->with(['production:id,app_id,name,slug,user_id,app_slug', 'artists:id,app_id,slug,stage_name'])
            ->withCount(['tickets' => fn ($q) => $q->where('app_id', $appId)]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")->orWhere('venue', 'like', "%{$term}%"));
        }
        if (! empty($data['city'])) $query->whereRaw('LOWER(city) = LOWER(?)', [$data['city']]);
        if (! empty($data['production_id'])) $query->where('production_id', $data['production_id']);
        if (! empty($data['from'])) $query->where('start_date', '>=', Carbon::createFromFormat('Y-m-d', $data['from'], config('app.timezone'))->startOfDay());
        if (! empty($data['to'])) $query->where('start_date', '<=', Carbon::createFromFormat('Y-m-d', $data['to'], config('app.timezone'))->endOfDay());

        match ($data['status'] ?? null) {
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            'upcoming' => $query->where('is_cancelled', false)->where('end_date', '>', now()),
            'past' => $query->where('end_date', '<=', now()),
            default => null,
        };

        return response()->json(['events' => $query->orderByDesc('start_date')->paginate($data['per_page'] ?? 50)->appends($request->query())]);
    }

    public function show(Request $request, int $id)
    {
        $appId = $this->applicationId();
        $event = $this->ownedEvent($id, $this->requestUser($request));
        $event->load(['production:id,app_id,name,slug,user_id,app_slug', 'artists:id,app_id,slug,stage_name']);
        $event->loadCount(['tickets' => fn ($query) => $query->where('app_id', $appId)]);
        return response()->json(['event' => $event]);
    }

    public function store(Request $request)
    {
        $user = $this->requestUser($request);
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(true), $this->messages(), $this->attributes());
        $production = $this->ownedProduction((int) $data['production_id'], $user);
        $this->validateDates($data, null);

        if (empty($data['city']) && $production->city) $data['city'] = $production->city;
        if (empty($data['uf']) && $production->uf) $data['uf'] = $production->uf;

        $data['app_id'] = $this->applicationId();
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
            'message' => 'Evento criado como rascunho. Configure ingressos, line-up e publique quando estiver pronto.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->requestUser($request);
        $event = $this->ownedEvent($id, $user);
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(false), $this->messages(), $this->attributes());
        if (isset($data['production_id'])) $this->ownedProduction((int) $data['production_id'], $user);
        $this->validateDates($data, $event);
        if (! empty($data['title']) && $data['title'] !== $event->title) $data['slug'] = $this->uniqueSlug($data['title'], $event->id);

        unset($data['image'], $data['app_id'], $data['app_slug'], $data['is_published'], $data['is_cancelled']);
        $event->update($data);

        if ($request->hasFile('image')) {
            if ($event->image && str_starts_with($event->image, 'images/cutinapp/events/')) Storage::disk('public')->delete($event->image);
            $event->image = $this->storeImage($request->file('image'));
            $event->save();
        }

        return response()->json(['message' => 'Evento atualizado com sucesso.', 'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function publish(Request $request, int $id)
    {
        $event = $this->ownedEvent($id, $this->requestUser($request));
        abort_if($event->is_cancelled, 422, 'Um evento cancelado não pode ser publicado.');
        abort_if(! $event->end_date || $event->end_date->lte(now()), 422, 'Um evento já encerrado não pode ser publicado.');
        abort_if(! $event->start_date || $event->start_date->lte(now()), 422, 'A data de início precisa estar no futuro para publicar o evento.');

        $hasAvailableCourtesy = Ticket::query()
            ->where('app_id', $this->applicationId())->where('event_id', $event->id)->where('price', 0)->where('quantity', '>', 0)
            ->where(fn ($q) => $q->whereNull('limit_date')->orWhere('limit_date', '>', now()))->exists();
        abort_unless($hasAvailableCourtesy, 422, 'Crie ao menos um ingresso disponível antes de publicar o evento.');

        $wasPublished = (bool) $event->is_published;
        $event->forceFill(['is_published' => true])->save();
        if (! $wasPublished && ! $event->is_private) $this->notifyProductionFollowers($event);

        $message = $event->is_private
            ? 'Evento privado ativado. Ele permanece fora da descoberta e dos perfis públicos.'
            : 'Evento publicado. A página pública já está disponível.';

        return response()->json(['message' => $message, 'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function unpublish(Request $request, int $id)
    {
        $event = $this->ownedEvent($id, $this->requestUser($request));
        $event->forceFill(['is_published' => false])->save();
        return response()->json(['message' => 'Evento retirado da publicação. Os ingressos já emitidos foram preservados.', 'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return [
            'production_id' => $required . 'integer|exists:productions,id',
            'title' => $required . 'string|min:2|max:255',
            'description' => $required . 'string|max:50000',
            'category' => 'sometimes|nullable|string|max:120',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'address' => $required . 'string|max:500',
            'google_maps_url' => 'sometimes|nullable|url:http,https|max:2048',
            'start_date' => $required . 'date',
            'end_date' => $required . 'date',
            'venue' => 'sometimes|nullable|string|max:255',
            'uf' => 'sometimes|nullable|string|size:2',
            'city' => 'sometimes|nullable|string|max:120',
            'cep' => 'sometimes|nullable|string|max:20',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'max_attendees' => 'sometimes|nullable|integer|min:1|max:1000000',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_private' => 'sometimes|boolean',
        ];
    }

    private function normalizeInput(Request $request): void
    {
        $merge = [];
        $dateErrors = [];
        if ($request->has('uf')) $merge['uf'] = strtoupper(trim((string) $request->input('uf')));
        if ($request->has('category')) { $value = trim((string) $request->input('category')); $merge['category'] = $value === '' ? null : $value; }
        if ($request->has('google_maps_url')) { $url = trim((string) $request->input('google_maps_url')); $merge['google_maps_url'] = $url === '' ? null : $url; }

        foreach (['start_date', 'end_date'] as $field) {
            if (! $request->filled($field)) continue;
            try { $merge[$field] = Carbon::parse((string) $request->input($field), config('app.timezone'))->format('Y-m-d H:i:s'); }
            catch (Throwable) { $dateErrors[$field][] = $field === 'start_date' ? 'Informe uma data de início válida.' : 'Informe uma data de término válida.'; }
        }
        if ($dateErrors !== []) throw ValidationException::withMessages($dateErrors);
        if ($merge !== []) $request->merge($merge);
    }

    private function validateDates(array $data, ?Event $event): void
    {
        $startValue = $data['start_date'] ?? $event?->start_date;
        $endValue = $data['end_date'] ?? $event?->end_date;
        if (! $startValue || ! $endValue) return;
        $timezone = config('app.timezone');
        $start = Carbon::parse($startValue, $timezone);
        $end = Carbon::parse($endValue, $timezone);
        $errors = [];

        if ($event === null) {
            $minimumStart = Carbon::now($timezone)->addDay()->startOfDay();
            if ($start->lt($minimumStart)) $errors['start_date'][] = 'O evento precisa ser criado com pelo menos um dia de antecedência. Escolha uma data a partir de amanhã.';
        } elseif (array_key_exists('start_date', $data) && $start->lt(now()->subMinute())) {
            $errors['start_date'][] = 'O início do evento não pode ficar no passado.';
        }
        if (! $end->gt($start)) $errors['end_date'][] = 'O término do evento precisa ser posterior ao início.';
        if ($start->diffInDays($end) > 30) $errors['end_date'][] = 'A duração do evento não pode ultrapassar 30 dias.';
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    private function ownedProduction(int $id, User $user): Production
    {
        $production = Production::query()->where('app_id', $this->applicationId())->where('app_slug', self::APP)->findOrFail($id);
        abort_unless($user->hasProfile('Administrador') || (int) $production->user_id === (int) $user->id, 403, 'Você não pode gerenciar esta produção.');
        return $production;
    }

    private function ownedEvent(int $id, User $user): Event
    {
        $event = Event::query()->where('app_id', $this->applicationId())->where('app_slug', self::APP)->with('production')->findOrFail($id);
        abort_unless($event->production && (int) $event->production->app_id === $this->applicationId(), 404, 'Evento não encontrado na Cutinapp.');
        abort_unless($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id, 403, 'Você não pode gerenciar este evento.');
        return $event;
    }

    private function notifyProductionFollowers(Event $event): void
    {
        if ($event->is_private) return;

        $appId = $this->applicationId();
        $followers = DB::table('cutinapp_follows')->where(['app_id' => $appId, 'target_type' => 'production', 'target_id' => $event->production_id])->pluck('user_id');
        foreach ($followers as $userId) {
            AppNotification::create([
                'app_id' => $appId, 'user_id' => $userId, 'type' => 'production_event_published',
                'title' => 'Novo evento publicado', 'message' => $event->production?->name . ' publicou ' . $event->title . '.',
                'reference_type' => 'event', 'reference_id' => $event->id, 'reference_url' => '/event/' . $event->slug,
                'data' => ['production_id' => $event->production_id, 'event_id' => $event->id],
            ]);
        }
    }

    private function requestUser(Request $request): User
    {
        $user = $this->optionalRequestUser($request);
        abort_unless($user instanceof User, 401, 'Sua sessão expirou. Entre novamente.');
        return $user;
    }

    private function optionalRequestUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) return null;
        try {
            $user = JWTAuth::setToken($token)->authenticate();
            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function applicationId(): int
    {
        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->first();
        abort_unless($application, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $application->id;
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'evento'; $slug = $base; $counter = 2;
        while (Event::query()->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->where('slug', $slug)->exists()) $slug = $base . '-' . $counter++;
        return $slug;
    }

    private function storeImage($file): string
    {
        $directory = 'images/cutinapp/events';
        $path = $directory . '/' . Str::uuid() . '.webp';
        $image = Image::make($file)->orientate()->resize(1920, 1080, function ($constraint) { $constraint->aspectRatio(); $constraint->upsize(); })->encode('webp', 86);
        Storage::disk('public')->put($path, (string) $image);
        return $path;
    }

    private function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.', 'min' => ':attribute está abaixo do tamanho mínimo permitido.',
            'integer' => ':attribute precisa ser um número inteiro.', 'exists' => ':attribute não foi encontrado ou não está mais disponível.',
            'date' => 'Informe uma data válida em :attribute.', 'date_format' => 'Informe uma data válida em :attribute.',
            'email' => 'Informe um e-mail válido.', 'url' => 'Informe uma URL completa iniciando com https://.',
            'image' => ':attribute precisa ser uma imagem válida.', 'mimes' => ':attribute deve ser JPG, PNG ou WebP.',
            'max' => ':attribute ultrapassou o limite permitido.', 'size' => ':attribute precisa ter :size caracteres.',
            'between' => ':attribute está fora do intervalo permitido.',
        ];
    }

    private function attributes(): array
    {
        return [
            'production_id' => 'a produção', 'title' => 'o nome do evento', 'description' => 'a descrição', 'category' => 'a categoria',
            'image' => 'a imagem', 'address' => 'o endereço', 'google_maps_url' => 'o link do Google Maps', 'start_date' => 'a data de início',
            'end_date' => 'a data de término', 'venue' => 'o local', 'uf' => 'a UF', 'city' => 'a cidade', 'latitude' => 'a latitude',
            'longitude' => 'a longitude', 'max_attendees' => 'a capacidade', 'contact_email' => 'o e-mail de contato', 'contact_phone' => 'o telefone de contato',
        ];
    }
}
