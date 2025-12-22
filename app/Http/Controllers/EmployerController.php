<?php

namespace App\Http\Controllers;

use App\Models\{
    Employer,
    Establishment,
    EmployerSchedule,
    Order,
    Interaction
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    Auth,
    Mail,
    Cache,
};
use Carbon\Carbon;
use App\Mail\{
    NewEmployerCollaborator,
    OwnerNotifiedNewCollaborator,
    EmployerRemoved,
    OwnerNotifiedEmployerDetached
};
use GeoIP; // facade do pacote torann/geoip

class EmployerController extends Controller
{
    /* =======================================================
     | Helpers
     ======================================================= */

    private function sanitizeForJson($value)
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            return iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }

        if ($value instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->sanitizeForJson($value->toArray());
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $this->sanitizeForJson($value->toArray());
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeForJson'], $value);
        }

        return $value;
    }

    private function jsonUtf8($data, int $status = 200)
    {
        return response()->json(
            $this->sanitizeForJson($data),
            $status,
            [],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /* =======================================================
     | PUBLIC
     ======================================================= */
    public function view(string $identifier)
    {
        try {
            $employer = Employer::query()
                ->when(
                    is_numeric($identifier),
                    fn($q) => $q->where('id', (int) $identifier),
                    fn($q) => $q->whereHas('user', fn($uq) => $uq->where('user_name', $identifier))
                )
                ->with([
                    'user',
                    'establishment',
                    'files' => fn($q) =>
                        $q->where('entity_name', 'employer')
                            ->orderBy('position'),
                ])
                ->first();

            if (!$employer) {
                \Log::warning('Employer.view not found', [
                    'identifier' => $identifier,
                ]);

                return response()->json([
                    'success' => false,
                    'employer' => null,
                ], 404);
            }

            Interaction::registerView($employer, auth()->user() ?? null);

            $employerArray = json_decode(
                json_encode($employer->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                true
            );

            return response()->json([
                'success' => true,
                'message' => 'employer encontrado com sucesso',
                'employer' => $employerArray,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Employer.view error', [
                'identifier' => $identifier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar employer',
            ], 500);
        }
    }
    public function home(Request $request, $app_id)
    {
        try {
            $city = $request->query('city');
            $uf = $request->query('uf');

            // Se city ou uf não forem fornecidos, tenta obter pelo IP
            if (!$city || !$uf) {
                $ip = $request->ip();
                $location = geoip($ip);
                $city = $city ?? $location->city;
                $uf = $uf ?? $location->state;
            }

            $employers = Employer::whereHas('establishment', function ($q) use ($app_id, $city, $uf) {
                $q->where('app_id', $app_id)
                    ->when($city && $uf, fn($qq) => $qq->where('city', $city)->where('uf', $uf));
            })
                ->with([
                    'user.files' => fn($q) => $q->where('entity_name', 'user')->orderBy('position'),
                    'establishment.files' => fn($q) => $q->where('entity_name', 'establishment')->orderBy('position'),
                ])
                ->withCount([
                    'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
                    'views as unique_users' => fn($q) => $q->select(\DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view'),
                ])
                ->get();

            return response()->json([
                'success' => true,
                'employers' => $employers,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Employer.home error', [
                'app_id' => $app_id,
                'city' => $city,
                'uf' => $uf,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar colaboradores',
            ], 500);
        }
    }


    /* =======================================================
     | COLLABORATORS
     ======================================================= */

    public function store(Request $request)
    {
        if (!Auth::check()) {
            return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
        ]);

        $owner = Auth::user();
        $establishment = Establishment::findOrFail($data['establishment_id']);

        if ((int) $establishment->user_id !== (int) $owner->id) {
            return $this->jsonUtf8(['error' => 'Apenas o dono pode adicionar colaboradores.'], 403);
        }

        if (
            Employer::where('user_id', $data['user_id'])
                ->where('establishment_id', $data['establishment_id'])
                ->exists()
        ) {
            return $this->jsonUtf8(['error' => 'Usuário já vinculado ao estabelecimento.'], 409);
        }

        $employer = Employer::create([
            'user_id' => $data['user_id'],
            'establishment_id' => $data['establishment_id'],
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? [],
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        Mail::to($establishment->user->email)
            ->queue(new OwnerNotifiedNewCollaborator($establishment, $employer));

        Mail::to($employer->user->email)
            ->queue(new NewEmployerCollaborator($establishment, $employer));

        return $this->jsonUtf8([
            'message' => 'Colaborador adicionado com sucesso.',
            'employer' => $employer,
        ], 201);
    }

    public function detach(Request $request)
    {
        if (!Auth::check()) {
            return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
        }

        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);

        $owner = Auth::user();
        $establishment = Establishment::findOrFail($data['establishment_id']);

        if ((int) $establishment->user_id !== (int) $owner->id) {
            return $this->jsonUtf8(['error' => 'Apenas o dono pode remover colaboradores.'], 403);
        }

        $employer = Employer::with('user')
            ->where('id', $data['employer_id'])
            ->where('establishment_id', $establishment->id)
            ->firstOrFail();

        $collaborator = $employer->user;

        $employer->delete();

        Mail::to($collaborator->email)
            ->queue(new EmployerRemoved($establishment, $collaborator));

        Mail::to($owner->email)
            ->queue(new OwnerNotifiedEmployerDetached($establishment, $collaborator));

        return $this->jsonUtf8(['message' => 'Colaborador removido com sucesso.']);
    }
    public function listOthers(string $identifier)
    {
        try {
            return Cache::remember("establishments_others_{$identifier}", 300, function () use ($identifier) {

                $establishment = Establishment::query()
                    ->when(
                        is_numeric($identifier),
                        fn($q) => $q->where('id', (int) $identifier),
                        fn($q) => $q->where('slug', $identifier)
                    )
                    ->firstOrFail();

                $establishments = Establishment::query()
                    ->where('app_id', $establishment->app_id)
                    ->where('uf', $establishment->uf)
                    ->where('city', $establishment->city)
                    ->where('id', '!=', $establishment->id)
                    ->with([
                        'files' => fn($q) =>
                            $q->where('entity_name', 'establishment')
                                ->orderBy('position'),
                    ])
                    ->orderByDesc('updated_at')
                    ->get()
                    ->map(function ($est) {
                        return json_decode(
                            json_encode($est->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                            true
                        );
                    })
                    ->values();

                return [
                    'success' => true,
                    'message' => 'Estabelecimentos listados com sucesso.',
                    'establishments' => $establishments,
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('Establishment.listOthers error', [
                'identifier' => $identifier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar outros estabelecimentos',
            ], 500);
        }
    }



    public function listByEntity(string $identifier)
    {
        try {
            return Cache::remember("employers_entity_{$identifier}", 300, function () use ($identifier) {

                $establishment = Establishment::query()
                    ->when(
                        is_numeric($identifier),
                        fn($q) => $q->where('id', (int) $identifier),
                        fn($q) => $q->where('slug', $identifier)
                    )
                    ->firstOrFail();

                $employers = Employer::query()
                    ->where('establishment_id', $establishment->id)
                    ->with([
                        'files' => fn($q) =>
                            $q->where('entity_name', 'employer')
                                ->orderBy('position'),

                        'user.files' => fn($q) =>
                            $q->where('entity_name', 'user')
                                ->orderBy('position'),

                        'establishment.files' => fn($q) =>
                            $q->where('entity_name', 'establishment')
                                ->orderBy('position'),
                    ])
                    ->get()
                    ->map(function ($employer) {
                        return json_decode(
                            json_encode($employer->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                            true
                        );
                    })
                    ->values();

                return [
                    'success' => true,
                    'message' => 'Colaboradores listados com sucesso.',
                    'employers' => $employers,
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('Employer.listByEntity error', [
                'identifier' => $identifier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar colaboradores',
            ], 500);
        }
    }



    /* =======================================================
     | SCHEDULES
     ======================================================= */

    public function listSchedules(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
        ]);

        $schedules = EmployerSchedule::where('employer_id', $data['employer_id'])
            ->orderByRaw("FIELD(day_of_week,'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
            ->orderBy('start_time')
            ->get();

        return $this->jsonUtf8($schedules);
    }

    public function saveSchedules(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'schedules' => 'nullable|array',
            'schedules.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
        ]);

        EmployerSchedule::where('employer_id', $data['employer_id'])
            ->where('type', 'work')
            ->delete();

        foreach ($data['schedules'] ?? [] as $s) {
            EmployerSchedule::create([
                'employer_id' => $data['employer_id'],
                'day_of_week' => $s['day_of_week'],
                'start_time' => $s['start_time'],
                'end_time' => $s['end_time'],
                'type' => 'work',
                'is_active' => true,
            ]);
        }

        return $this->jsonUtf8(['message' => 'Horários salvos com sucesso.'], 201);
    }

    public function deleteSchedule(int $id)
    {
        EmployerSchedule::findOrFail($id)->delete();
        return $this->jsonUtf8(['message' => 'Horário removido com sucesso.']);
    }

    /* =======================================================
     | APPOINTMENTS / ORDERS
     ======================================================= */

    public function availableTimes(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'date' => 'required',
            'duration' => 'required|integer|min:5',
        ]);

        $employerId = (int) $data['employer_id'];
        $duration = (int) $data['duration'];

        $now = Carbon::now('America/Sao_Paulo');
        $date = Carbon::createFromFormat('Y-m-d', substr($data['date'], 0, 10), 'America/Sao_Paulo');
        $dayOfWeek = strtolower($date->format('l'));

        $schedules = EmployerSchedule::where('employer_id', $employerId)
            ->where('day_of_week', $dayOfWeek)
            ->where('type', 'work')
            ->where('is_active', true)
            ->get();

        if ($schedules->isEmpty()) {
            return $this->jsonUtf8(['available_times' => []]);
        }

        $appointments = Order::where('attendant_id', $employerId)
            ->where('type', 'appointment')
            ->whereDate('order_datetime', $date->toDateString())
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->get(['order_datetime', 'total_duration']);

        $occupied = [];

        foreach ($appointments as $a) {
            $start = Carbon::parse($a->order_datetime)->setTimezone('America/Sao_Paulo');
            $end = $start->copy()->addMinutes($a->total_duration ?? 30);
            $occupied[] = [$start, $end];
        }

        $available = [];
        $step = 15;

        foreach ($schedules as $s) {
            $pointer = Carbon::parse("{$date->toDateString()} {$s->start_time}", 'America/Sao_Paulo');
            $endWork = Carbon::parse("{$date->toDateString()} {$s->end_time}", 'America/Sao_Paulo');

            while ($pointer->copy()->addMinutes($duration)->lte($endWork)) {
                $conflict = false;

                foreach ($occupied as [$os, $oe]) {
                    if ($pointer->lt($oe) && $pointer->copy()->addMinutes($duration)->gt($os)) {
                        $conflict = true;
                        break;
                    }
                }

                if (!$conflict && (!$date->isSameDay($now) || $pointer->gt($now))) {
                    $available[] = $pointer->format('H:i');
                }

                $pointer->addMinutes($step);
            }
        }

        sort($available);

        return $this->jsonUtf8(['available_times' => $available]);
    }

    public function reserveSchedule(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'date' => 'required|date',
            'type' => 'required|in:break,holiday',
            'start_time' => 'nullable|date_format:H:i|required_if:type,break',
            'end_time' => 'nullable|date_format:H:i|after:start_time|required_if:type,break',
        ]);

        $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

        EmployerSchedule::create([
            'employer_id' => $data['employer_id'],
            'day_of_week' => $dayOfWeek,
            'reserved_date' => $data['date'],
            'start_time' => $data['start_time'] ?? '00:00',
            'end_time' => $data['end_time'] ?? '23:59',
            'type' => $data['type'],
            'is_active' => false,
        ]);

        return $this->jsonUtf8(['message' => 'Horário reservado com sucesso.'], 201);
    }

    public function listAppointments(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'nullable|integer|exists:employers,id',
        ]);

        $employer = isset($data['employer_id'])
            ? Employer::findOrFail($data['employer_id'])
            : Employer::where('user_id', Auth::id())->firstOrFail();

        $appointments = Order::with([
            'items.item',
            'client',
            'attendant.user'
        ])
            ->where('type', 'appointment')
            ->where('attendant_id', $employer->id)
            ->orderBy('order_datetime', 'desc')
            ->get();

        return $this->jsonUtf8([
            'appointments' => $appointments,
            'count' => $appointments->count(),
        ]);
    }

    public function checkUpdates(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'last_check' => 'nullable|date',
        ]);

        $lastCheck = isset($data['last_check'])
            ? Carbon::parse($data['last_check'])
            : now()->subMinutes(10);

        $query = Order::where('attendant_id', $data['employer_id'])
            ->where('type', 'appointment');

        return $this->jsonUtf8([
            'checked_at' => now()->toDateTimeString(),
            'total' => $query->count(),
            'new' => $query->where('created_at', '>', $lastCheck)->count(),
        ]);
    }

    public function listMyOrders()
    {
        $employer = Employer::where('user_id', Auth::id())->firstOrFail();

        $orders = Order::with(['items.item', 'client'])
            ->where('attendant_id', $employer->id)
            ->orderByDesc('order_datetime')
            ->get();

        return $this->jsonUtf8([
            'orders' => $orders,
            'count' => $orders->count(),
        ]);
    }


    public function listByItem(string $identifier)
    {
        // Buscando o item pelo ID ou slug
        $item = \App\Models\Item::query()
            ->when(is_numeric($identifier), fn($q) => $q->where('id', $identifier), fn($q) => $q->where('slug', $identifier))
            ->with('establishment')
            ->firstOrFail();

        $establishment = $item->establishment;

        if (!$establishment) {
            return $this->jsonUtf8(['message' => 'Estabelecimento não encontrado para este item.'], 404);
        }

        $employers = $establishment->employers
            ->filter(fn($e) => $e->user) // garante que o employer tenha usuário
            ->map(function ($e) use ($item) {
                $u = $e->user;
                $avatar =
                    $u->files->firstWhere('type', 'avatar')?->public_url
                    ?? $u->avatar
                    ?? null;

                // Contando quantas vezes o employer atendeu este item
                $attendedCount = Order::where('attendant_id', $e->id)
                    ->where('type', 'appointment')
                    ->whereHas('items', fn($q) => $q->where('item_id', $item->id))
                    ->count();

                return [
                    'id' => $e->id,
                    'role' => $e->role,
                    'permissions' => $e->permissions,
                    'attended_count' => $attendedCount,
                    'user' => [
                        'id' => $u->id,
                        'user_name' => $u->user_name,
                        'first_name' => $u->first_name,
                        'last_name' => $u->last_name,
                        'avatar' => $avatar,
                        'city' => $u->city,
                        'uf' => $u->uf,
                    ],
                ];
            })
            ->values();

        return $this->jsonUtf8([
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
            ],
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'slug' => $establishment->slug,
            ],
            'employers' => $employers,
            'total' => $employers->count(),
        ]);
    }

}
