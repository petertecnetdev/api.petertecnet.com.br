<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CutinappController extends Controller
{
    private const APP = 'cutinapp';

    public function config()
    {
        $clientId = trim((string) config('services.google.client_id'));
        $application = $this->application();

        return response()->json([
            'google_client_id' => $clientId,
            'google_configured' => $clientId !== '',
            'app' => self::APP,
            'app_id' => $application->id,
        ]);
    }

    public function publicEvents(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ], $this->validationMessages(), $this->validationAttributes());

        $appId = $this->applicationId();
        $query = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
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

    public function myProductions()
    {
        $appId = $this->applicationId();

        return response()->json([
            'productions' => Production::query()
                ->where('app_id', $appId)
                ->where('app_slug', self::APP)
                ->where('user_id', Auth::id())
                ->withCount(['events' => fn ($query) => $query->where('app_id', $appId)])
                ->latest()
                ->get(),
        ]);
    }

    public function showProduction(int $id)
    {
        return response()->json(['production' => $this->ownedProduction($id)]);
    }

    public function createProduction(Request $request)
    {
        $this->normalizeProductionInput($request);
        $data = $request->validate(
            $this->productionRules(true),
            $this->validationMessages(),
            $this->validationAttributes()
        );

        $application = $this->application();
        $user = Auth::user();
        abort_unless($user, 401, 'Faça login para criar uma produção.');

        $data['user_id'] = $user->id;
        $data['app_id'] = $application->id;
        $data['app_slug'] = self::APP;
        $data['slug'] = $this->uniqueProductionSlug($data['name']);
        $data['is_published'] = true;
        $data['is_cancelled'] = false;
        unset($data['logo'], $data['background']);

        $production = Production::create($data);
        $this->storeProductionImages($request, $production);
        $this->registerParticipation($application, $user->id, 'producer');

        return response()->json([
            'message' => 'Produção criada com sucesso. Agora você pode cadastrar o primeiro evento.',
            'production' => $production->fresh(),
        ], 201);
    }

    public function updateProduction(Request $request, int $id)
    {
        $production = $this->ownedProduction($id);
        $this->normalizeProductionInput($request);
        $data = $request->validate(
            $this->productionRules(false),
            $this->validationMessages(),
            $this->validationAttributes()
        );

        if (! empty($data['name']) && $data['name'] !== $production->name) {
            $data['slug'] = $this->uniqueProductionSlug($data['name'], $production->id);
        }

        unset($data['logo'], $data['background'], $data['user_id'], $data['app_id'], $data['app_slug']);
        $production->update($data);
        $this->storeProductionImages($request, $production);

        return response()->json([
            'message' => 'Produção atualizada com sucesso.',
            'production' => $production->fresh(),
        ]);
    }

    public function myEvents(Request $request)
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

    public function showEvent(int $id)
    {
        $appId = $this->applicationId();
        $event = $this->ownedEvent($id);
        $event->load('production:id,app_id,name,slug,user_id,app_slug');
        $event->loadCount(['tickets' => fn ($query) => $query->where('app_id', $appId)]);

        return response()->json(['event' => $event]);
    }

    public function createEvent(Request $request)
    {
        $data = $request->validate(
            $this->eventRules(true),
            $this->validationMessages(),
            $this->validationAttributes()
        );
        $production = $this->ownedProduction((int) $data['production_id']);
        $appId = $this->applicationId();

        abort_unless((int) $production->app_id === $appId, 422, 'A produção selecionada não pertence à Cutinapp.');

        $data['app_id'] = $appId;
        $data['app_slug'] = self::APP;
        $data['slug'] = $this->uniqueEventSlug($data['title']);
        $data['is_published'] = false;
        $data['is_cancelled'] = false;
        unset($data['image']);

        $event = Event::create($data);
        if ($request->hasFile('image')) {
            $event->image = $this->storeEventImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento criado como rascunho. Configure a cortesia e publique quando estiver pronto.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ], 201);
    }

    public function updateEvent(Request $request, int $id)
    {
        $event = $this->ownedEvent($id);
        $data = $request->validate(
            $this->eventRules(false),
            $this->validationMessages(),
            $this->validationAttributes()
        );

        if (isset($data['production_id'])) {
            $production = $this->ownedProduction((int) $data['production_id']);
            abort_unless((int) $production->app_id === $this->applicationId(), 422, 'A produção selecionada não pertence à Cutinapp.');
        }
        if (! empty($data['title']) && $data['title'] !== $event->title) {
            $data['slug'] = $this->uniqueEventSlug($data['title'], $event->id);
        }

        unset($data['image'], $data['app_id'], $data['app_slug'], $data['is_published'], $data['is_cancelled']);
        $event->update($data);

        if ($request->hasFile('image')) {
            if ($event->image && str_starts_with($event->image, 'images/cutinapp/events/')) {
                Storage::disk('public')->delete($event->image);
            }
            $event->image = $this->storeEventImage($request->file('image'));
            $event->save();
        }

        return response()->json([
            'message' => 'Evento atualizado com sucesso.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ]);
    }

    public function publishEvent(int $id)
    {
        $event = $this->ownedEvent($id);
        abort_if($event->is_cancelled, 422, 'Um evento cancelado não pode ser publicado.');

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

    public function unpublishEvent(int $id)
    {
        $event = $this->ownedEvent($id);
        $event->forceFill(['is_published' => false])->save();

        return response()->json([
            'message' => 'Evento retirado da publicação. Os ingressos já emitidos foram preservados.',
            'event' => $event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug'),
        ]);
    }

    public function createCourtesy(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ], $this->validationMessages(), $this->validationAttributes());

        $event = $this->ownedEvent((int) $data['event_id']);
        abort_if($event->is_cancelled, 422, 'Não é possível criar ingressos para um evento cancelado.');

        $ticket = Ticket::create([
            'app_id' => $this->applicationId(),
            'app_slug' => self::APP,
            'event_id' => $event->id,
            'name' => $data['name'],
            'ticket_type' => 'courtesy',
            'type' => 'courtesy',
            'price' => 0,
            'quantity' => $data['quantity'],
            'limit_date' => $data['limit_date'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Cortesia criada. Agora você pode publicar o evento.',
            'ticket' => $ticket->loadCount('passes'),
        ], 201);
    }

    public function eventCourtesies(int $eventId)
    {
        $event = $this->ownedEvent($eventId);
        $tickets = Ticket::query()
            ->where('app_id', $this->applicationId())
            ->where('event_id', $event->id)
            ->withCount('passes')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'event' => $event->only(['id', 'title', 'slug', 'is_published', 'is_cancelled']),
            'tickets' => $tickets,
        ]);
    }

    public function updateCourtesy(Request $request, int $ticketId)
    {
        $ticket = $this->ownedTicket($ticketId);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ], $this->validationMessages(), $this->validationAttributes());

        $issued = $ticket->passes()->count();
        if (isset($data['quantity'])) {
            abort_if((int) $data['quantity'] < $issued, 422, "A quantidade não pode ser menor que os {$issued} ingressos já emitidos.");
        }

        $ticket->update($data);

        return response()->json([
            'message' => 'Cortesia atualizada.',
            'ticket' => $ticket->fresh()->loadCount('passes'),
        ]);
    }

    public function deleteCourtesy(int $ticketId)
    {
        $ticket = $this->ownedTicket($ticketId);
        abort_if($ticket->passes()->exists(), 409, 'Esta cortesia já possui ingressos emitidos e não pode ser excluída.');
        $ticket->delete();

        return response()->json(['message' => 'Cortesia removida.']);
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

    private function ownedTicket(int $ticketId): Ticket
    {
        $ticket = Ticket::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->with('event.production')
            ->findOrFail($ticketId);

        abort_unless(
            $ticket->event && (int) $ticket->event->app_id === $this->applicationId(),
            404,
            'Ingresso não encontrado na Cutinapp.'
        );
        $this->ownedEvent((int) $ticket->event_id);

        return $ticket;
    }

    private function productionRules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'name' => $required . 'string|min:2|max:255',
            'fantasy' => 'sometimes|nullable|string|max:255',
            'cnpj' => ['sometimes', 'nullable', 'regex:/^\d{14}$/'],
            'phone' => 'sometimes|nullable|string|max:30',
            'description' => 'sometimes|nullable|string|max:10000',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'address' => 'sometimes|nullable|string|max:255',
            'website_url' => 'sometimes|nullable|url:http,https|max:2048',
            'instagram_url' => 'sometimes|nullable|url:http,https|max:2048',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function eventRules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'production_id' => $required . 'integer|exists:productions,id',
            'title' => $required . 'string|max:255',
            'description' => $required . 'string|max:50000',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'address' => $required . 'string|max:500',
            'start_date' => $required . 'date',
            'end_date' => $required . 'date|after_or_equal:start_date',
            'venue' => 'sometimes|nullable|string|max:255',
            'uf' => 'sometimes|nullable|string|size:2',
            'city' => 'sometimes|nullable|string|max:120',
            'cep' => 'sometimes|nullable|string|max:20',
            'max_attendees' => 'sometimes|nullable|integer|min:0',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_private' => 'sometimes|boolean',
        ];
    }

    private function validationMessages(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'min' => ':attribute está abaixo do tamanho mínimo permitido.',
            'integer' => ':attribute precisa ser um número inteiro.',
            'exists' => ':attribute não foi encontrado ou não está mais disponível.',
            'date' => 'Informe uma data válida em :attribute.',
            'after_or_equal' => 'A data final precisa ser igual ou posterior à data inicial.',
            'email' => 'Informe um e-mail válido.',
            'url' => 'Informe uma URL completa, por exemplo https://exemplo.com.br.',
            'regex' => 'Informe um CNPJ válido com 14 números.',
            'image' => ':attribute precisa ser uma imagem válida.',
            'mimes' => ':attribute deve ser JPG, PNG ou WebP.',
            'max' => ':attribute ultrapassou o limite permitido.',
            'size' => ':attribute precisa ter :size caracteres.',
        ];
    }

    private function validationAttributes(): array
    {
        return [
            'name' => 'o nome da produção',
            'fantasy' => 'o nome fantasia',
            'cnpj' => 'o CNPJ',
            'phone' => 'o telefone',
            'description' => 'a descrição',
            'city' => 'a cidade',
            'uf' => 'a UF',
            'address' => 'o endereço',
            'website_url' => 'o site',
            'instagram_url' => 'o Instagram',
            'logo' => 'a logo',
            'background' => 'a capa',
            'production_id' => 'a produção',
            'title' => 'o título do evento',
            'start_date' => 'a data inicial',
            'end_date' => 'a data final',
            'event_id' => 'o evento',
            'quantity' => 'a quantidade',
        ];
    }

    private function normalizeProductionInput(Request $request): void
    {
        $input = [];
        foreach (['name', 'fantasy', 'phone', 'description', 'city', 'address'] as $field) {
            if ($request->exists($field)) {
                $input[$field] = trim((string) $request->input($field));
            }
        }

        if ($request->exists('uf')) {
            $input['uf'] = strtoupper(trim((string) $request->input('uf')));
        }
        if ($request->filled('cnpj')) {
            $input['cnpj'] = preg_replace('/\D+/', '', (string) $request->input('cnpj'));
        }
        if ($request->exists('website_url')) {
            $input['website_url'] = $this->normalizeUrl((string) $request->input('website_url'));
        }
        if ($request->exists('instagram_url')) {
            $input['instagram_url'] = $this->normalizeInstagram((string) $request->input('instagram_url'));
        }

        $request->merge($input);
    }

    private function normalizeUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }
        return $value;
    }

    private function normalizeInstagram(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '@')) {
            return 'https://instagram.com/' . ltrim($value, '@');
        }
        if (! preg_match('#^https?://#i', $value)) {
            if (! str_contains($value, '.')) {
                return 'https://instagram.com/' . ltrim($value, '/');
            }
            $value = 'https://' . $value;
        }
        return $value;
    }

    private function application(): Application
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless(
            $application,
            503,
            'A Cutinapp não está registrada corretamente na API. Execute as migrations e tente novamente.'
        );

        return $application;
    }

    private function applicationId(): int
    {
        return (int) $this->application()->id;
    }

    private function registerParticipation(Application $application, int $userId, string $role): void
    {
        $application->users()->syncWithoutDetaching([
            $userId => [
                'role' => $role,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function storeProductionImages(Request $request, Production $production): void
    {
        foreach (['logo' => [600, 600], 'background' => [1920, 700]] as $field => $size) {
            if (! $request->hasFile($field)) {
                continue;
            }
            if ($production->{$field} && str_starts_with($production->{$field}, 'images/cutinapp/productions/')) {
                Storage::disk('public')->delete($production->{$field});
            }

            $path = 'images/cutinapp/productions/' . $field . '-' . Str::uuid() . '.webp';
            $absolute = Storage::disk('public')->path($path);
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }

            Image::make($request->file($field)->getRealPath())
                ->orientate()
                ->fit($size[0], $size[1])
                ->encode('webp', 86)
                ->save($absolute);

            $production->{$field} = $path;
        }

        if ($production->isDirty(['logo', 'background'])) {
            $production->save();
        }
    }

    private function storeEventImage($file): string
    {
        $path = 'images/cutinapp/events/' . Str::uuid() . '.webp';
        $absolute = Storage::disk('public')->path($path);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        Image::make($file->getRealPath())->orientate()->fit(1400, 788)->encode('webp', 86)->save($absolute);
        return $path;
    }

    private function uniqueProductionSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'producao-' . Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;

        while (Production::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function uniqueEventSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'evento-' . Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;

        while (Event::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
