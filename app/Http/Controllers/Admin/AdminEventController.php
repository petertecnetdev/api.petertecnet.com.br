<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminEventController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:150'],
        ], [
            'search.required' => 'Informe nome, e-mail ou CPF para pesquisar o usuário.',
            'search.min' => 'Digite pelo menos 2 caracteres para pesquisar.',
        ]);

        $search = trim($data['search']);
        $digits = preg_replace('/\D+/', '', $search);

        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'cpf', 'avatar', 'city', 'uf'])
            ->withCount([
                'establishments as productions_count' => fn ($query) => $this->productionQuery($query),
            ])
            ->where(function ($query) use ($search, $digits) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ["%{$search}%"])
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%");

                if ($digits !== '') {
                    $query->orWhere('cpf', 'like', "%{$digits}%");
                }
            })
            ->orderByDesc('productions_count')
            ->orderBy('first_name')
            ->limit(30)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'cpf_masked' => $this->maskCpf($user->cpf),
                'avatar' => $user->avatar,
                'city' => $user->city,
                'uf' => $user->uf,
                'productions_count' => (int) $user->productions_count,
            ]);

        return response()->json(['users' => $users]);
    }

    public function productions(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request);

        $productions = Establishment::query()
            ->with(['app:id,name,slug', 'applications:id,name,slug'])
            ->where('user_id', $user->id)
            ->where(fn ($query) => $this->productionQuery($query))
            ->orderByDesc('is_published')
            ->orderByDesc('is_approved')
            ->orderBy('name')
            ->get()
            ->map(fn (Establishment $production) => [
                'id' => $production->id,
                'name' => $production->name,
                'fantasy' => $production->fantasy,
                'slug' => $production->slug,
                'city' => $production->city,
                'uf' => $production->uf,
                'address' => $production->address,
                'country' => $production->country,
                'phone' => $production->contact_phone ?: $production->phone,
                'email' => $production->contact_email ?: $production->email,
                'is_approved' => (bool) $production->is_approved,
                'is_published' => (bool) $production->is_published,
                'can_create_events' => ! (bool) $production->is_cancelled,
                'application' => $production->app,
                'applications' => $production->applications,
            ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'cpf_masked' => $this->maskCpf($user->cpf),
            ],
            'productions' => $productions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'production_id' => ['required', 'integer', 'exists:establishments,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'category' => ['nullable', 'string', 'max:150'],
            'event_format' => ['required', Rule::in(['in_person', 'online', 'hybrid'])],
            'start_date' => ['required', 'date', 'after_or_equal:now'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'max:120'],
            'online_platform' => ['nullable', 'string', 'max:120'],
            'online_url' => ['nullable', 'url', 'max:1000'],
            'online_instructions' => ['nullable', 'string', 'max:5000'],
            'max_attendees' => ['nullable', 'integer', 'min:0'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'approval_message' => ['nullable', 'string', 'max:5000'],
        ], [
            'user_id.required' => 'Selecione o usuário responsável pela produção.',
            'production_id.required' => 'Selecione a produção que receberá o evento.',
            'title.required' => 'Informe o nome do evento.',
            'description.required' => 'Informe a descrição do evento.',
            'event_format.required' => 'Selecione o formato do evento.',
            'start_date.required' => 'Informe a data e hora de início.',
            'start_date.after_or_equal' => 'O início do evento não pode ficar no passado.',
            'end_date.required' => 'Informe a data e hora de término.',
            'end_date.after' => 'O término do evento precisa ser posterior ao início.',
            'online_url.url' => 'Informe uma URL válida para o acesso online.',
        ]);

        $user = User::findOrFail((int) $data['user_id']);
        $production = Establishment::query()->findOrFail((int) $data['production_id']);

        if ((int) $production->user_id !== (int) $user->id || ! $this->isProduction($production)) {
            throw ValidationException::withMessages([
                'production_id' => ['A produção selecionada não pertence ao usuário informado ou não é do tipo production.'],
            ]);
        }

        if ((bool) $production->is_cancelled) {
            throw ValidationException::withMessages([
                'production_id' => ['Esta produção está desativada e não permite cadastrar eventos.'],
            ]);
        }

        $physical = in_array($data['event_format'], ['in_person', 'hybrid'], true);
        if ($physical) {
            $data['address'] = trim((string) ($data['address'] ?: $production->address));
            $data['city'] = trim((string) ($data['city'] ?: $production->city));
            $data['uf'] = strtoupper(trim((string) ($data['uf'] ?: $production->uf)));
            $data['venue'] = trim((string) ($data['venue'] ?: $production->fantasy ?: $production->name));

            $missing = [];
            if ($data['address'] === '') $missing['address'] = ['Informe o endereço do evento presencial.'];
            if ($data['city'] === '') $missing['city'] = ['Informe a cidade do evento presencial.'];
            if ($data['uf'] === '') $missing['uf'] = ['Informe a UF do evento presencial.'];
            if ($missing) throw ValidationException::withMessages($missing);
        }

        if (in_array($data['event_format'], ['online', 'hybrid'], true) && empty($data['online_url'])) {
            throw ValidationException::withMessages([
                'online_url' => ['Informe a URL de acesso da parte online do evento.'],
            ]);
        }

        $data['app_id'] = $production->app_id;
        $data['app_slug'] = $production->app_slug ?: $production->app?->slug;
        $data['slug'] = $this->uniqueEventSlug($data['slug'] ?: $data['title']);
        $data['country'] = $data['country'] ?: $production->country ?: 'Brasil';
        $data['contact_email'] = $data['contact_email'] ?: $production->contact_email ?: $production->email ?: $user->email;
        $data['contact_phone'] = $data['contact_phone'] ?: $production->contact_phone ?: $production->phone ?: $user->phone;
        $data['organizer_name'] = trim(implode(' ', array_filter([$user->first_name, $user->last_name]))) ?: $user->user_name ?: $user->email;
        $data['organizer_email'] = $user->email;
        $data['organizer_phone'] = $user->phone;
        $data['establishment_name'] = $production->fantasy ?: $production->name;
        $data['is_approved'] = $data['is_approved'] ?? true;
        $data['is_published'] = $data['is_published'] ?? false;
        $data['is_private'] = $data['is_private'] ?? false;
        $data['requires_approval'] = $data['requires_approval'] ?? false;

        unset($data['user_id']);

        $event = Event::create($data);

        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'event.created_for_production',
            'entity_type' => Event::class,
            'entity_id' => $event->id,
            'before' => null,
            'after' => [
                'event' => $event->toArray(),
                'owner_user_id' => $user->id,
                'production_id' => $production->id,
            ],
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return response()->json([
            'message' => 'Evento cadastrado com sucesso para a produção selecionada.',
            'event' => $event->load('production:id,name,fantasy,slug,user_id,city,uf'),
        ], 201);
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        $isPrimaryAdmin = $user && strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
        $isSuperAdmin = $user && $user->hasProfile('Super Admin');

        abort_unless($isPrimaryAdmin || $isSuperAdmin, 403, 'Usuário sem permissão para cadastrar eventos pelo Admin Center.');
    }

    private function productionQuery($query)
    {
        return $query
            ->where(function ($production) {
                $production->where('category', 'production')
                    ->orWhere('type', 'production')
                    ->orWhere('establishment_type', 'production');
            })
            ->where(function ($production) {
                $production->whereNull('is_cancelled')->orWhere('is_cancelled', false);
            });
    }

    private function isProduction(Establishment $establishment): bool
    {
        return in_array('production', array_filter([
            strtolower((string) $establishment->category),
            strtolower((string) $establishment->type),
            strtolower((string) $establishment->establishment_type),
        ]), true);
    }

    private function uniqueEventSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::random(12);
        $slug = $base;
        $suffix = 2;

        while (Event::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private function maskCpf(?string $cpf): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $cpf);
        if (strlen($digits) !== 11) return $cpf ? '***' : null;

        return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
    }
}
