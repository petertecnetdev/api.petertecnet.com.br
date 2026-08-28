<?php

namespace App\Http\Controllers;

use App\Mail\EmployerRemoved;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedEmployerDetached;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\Employer;
use App\Models\EmployerSchedule;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class EmployerController extends Controller
{
    public function view(string $identifier)
    {
        $employer = Employer::query()
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('user_name', $identifier)))
            ->with([
                'user:id,first_name,last_name,user_name,avatar,city,uf',
                'user.files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment:id,app_id,name,fantasy,slug,city,uf',
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
            ])
            ->firstOrFail();

        Interaction::registerView($employer, Auth::user());
        return response()->json(['success' => true, 'message' => 'Colaborador encontrado com sucesso.', 'employer' => $employer]);
    }

    public function home(Request $request, $app_id)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
        ]);

        $employers = Employer::query()
            ->whereHas('establishment', function ($q) use ($app_id, $data) {
                $q->where('app_id', (int) $app_id)->where('is_cancelled', false)
                    ->when(! empty($data['city']) && $data['city'] !== 'Todas', fn ($qq) => $qq->where('city', $data['city']))
                    ->when(! empty($data['uf']) && $data['uf'] !== 'ALL', fn ($qq) => $qq->where('uf', strtoupper($data['uf'])));
            })
            ->with([
                'user:id,first_name,last_name,user_name,avatar,city,uf',
                'user.files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active'),
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->get()
            ->shuffle()
            ->values();

        return response()->json([
            'success' => true,
            'city' => $data['city'] ?? null,
            'uf' => $data['uf'] ?? null,
            'employers' => $employers,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ]);

        $establishment = Establishment::findOrFail($data['establishment_id']);
        $this->assertEstablishmentOwner($establishment);

        if ((int) $data['user_id'] === (int) $establishment->user_id) {
            return response()->json(['error' => 'O proprietário não deve ser cadastrado como colaborador da própria empresa.'], 422);
        }
        if (Employer::where('user_id', $data['user_id'])->where('establishment_id', $establishment->id)->exists()) {
            return response()->json(['error' => 'Usuário já vinculado ao estabelecimento.'], 409);
        }

        $employer = Employer::create([
            'user_id' => $data['user_id'],
            'establishment_id' => $establishment->id,
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? [],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
        $employer->load(['user', 'establishment.user']);

        if ($establishment->user?->email) {
            Mail::to($establishment->user->email)->queue(new OwnerNotifiedNewCollaborator($establishment, $employer));
        }
        if ($employer->user?->email) {
            Mail::to($employer->user->email)->queue(new NewEmployerCollaborator($establishment, $employer));
        }

        return response()->json(['message' => 'Colaborador adicionado com sucesso.', 'employer' => $employer], 201);
    }

    public function detach(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);
        $establishment = Establishment::findOrFail($data['establishment_id']);
        $this->assertEstablishmentOwner($establishment);
        $employer = Employer::with('user')->where('id', $data['employer_id'])->where('establishment_id', $establishment->id)->firstOrFail();
        $collaborator = $employer->user;
        $employer->delete();

        if ($collaborator?->email) {
            Mail::to($collaborator->email)->queue(new EmployerRemoved($establishment, $collaborator));
        }
        if (Auth::user()->email) {
            Mail::to(Auth::user()->email)->queue(new OwnerNotifiedEmployerDetached($establishment, $collaborator));
        }

        return response()->json(['message' => 'Colaborador removido com sucesso.']);
    }

    public function listByEntity(string $identifier)
    {
        $establishment = $this->findEstablishment($identifier);
        $employers = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,city,uf',
                'user.files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active'),
            ])
            ->get();

        return response()->json(['success' => true, 'message' => 'Colaboradores listados com sucesso.', 'employers' => $employers]);
    }

    public function listByItem(string $identifier)
    {
        $item = Item::query()
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier))
            ->firstOrFail();
        abort_unless($item->entity_name === 'establishment', 404, 'Estabelecimento não encontrado para este item.');
        $establishment = Establishment::findOrFail($item->entity_id);

        $employers = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->with('user:id,first_name,last_name,user_name,avatar,city,uf')
            ->get()
            ->map(function ($employer) use ($item) {
                $employer->attended_count = Order::query()
                    ->where('attendant_id', $employer->id)
                    ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
                    ->count();
                return $employer;
            });

        return response()->json([
            'item' => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug],
            'establishment' => ['id' => $establishment->id, 'name' => $establishment->name, 'slug' => $establishment->slug],
            'employers' => $employers,
            'total' => $employers->count(),
        ]);
    }

    public function listOthers(string $identifier)
    {
        $current = $this->findEstablishment($identifier);
        return response()->json([
            'success' => true,
            'message' => 'Estabelecimentos listados com sucesso.',
            'establishments' => Establishment::query()
                ->where('app_id', $current->app_id)
                ->where('id', '!=', $current->id)
                ->where('is_cancelled', false)
                ->when($current->city, fn ($q) => $q->where('city', $current->city))
                ->when($current->uf, fn ($q) => $q->where('uf', $current->uf))
                ->limit(100)
                ->get(),
        ]);
    }

    public function listSchedules(Request $request)
    {
        $data = $request->validate(['employer_id' => 'required|integer|exists:employers,id']);
        $employer = Employer::findOrFail($data['employer_id']);
        $this->assertEmployerManager($employer);

        return response()->json(EmployerSchedule::where('employer_id', $employer->id)->orderBy('day_of_week')->orderBy('start_time')->get());
    }

    public function saveSchedules(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'schedules' => 'nullable|array|max:50',
            'schedules.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
        ]);
        $employer = Employer::findOrFail($data['employer_id']);
        $this->assertEmployerManager($employer);

        EmployerSchedule::where('employer_id', $employer->id)->where('type', 'work')->delete();
        foreach ($data['schedules'] ?? [] as $schedule) {
            EmployerSchedule::create(array_merge($schedule, ['employer_id' => $employer->id, 'type' => 'work', 'is_active' => true]));
        }

        return response()->json(['message' => 'Horários salvos com sucesso.'], 201);
    }

    public function deleteSchedule(int $id)
    {
        $schedule = EmployerSchedule::findOrFail($id);
        $employer = Employer::findOrFail($schedule->employer_id);
        $this->assertEmployerManager($employer);
        $schedule->delete();
        return response()->json(['message' => 'Horário removido com sucesso.']);
    }

    public function availableTimes(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'date' => 'required|date',
            'duration' => 'required|integer|min:5|max:1440',
        ]);
        $date = Carbon::parse($data['date'], 'America/Sao_Paulo')->startOfDay();
        $now = Carbon::now('America/Sao_Paulo');
        $day = strtolower($date->format('l'));
        $schedules = EmployerSchedule::where('employer_id', $data['employer_id'])->where('day_of_week', $day)->where('type', 'work')->where('is_active', true)->get();
        $orders = Order::where('attendant_id', $data['employer_id'])->where('type', 'appointment')->whereDate('order_datetime', $date)->whereIn('appointment_status', ['pending', 'confirmed'])->get();

        $available = [];
        foreach ($schedules as $schedule) {
            $pointer = Carbon::parse($date->toDateString() . ' ' . $schedule->start_time, 'America/Sao_Paulo');
            $workEnd = Carbon::parse($date->toDateString() . ' ' . $schedule->end_time, 'America/Sao_Paulo');
            while ($pointer->copy()->addMinutes($data['duration'])->lte($workEnd)) {
                $end = $pointer->copy()->addMinutes($data['duration']);
                $conflict = $orders->contains(function ($order) use ($pointer, $end) {
                    $start = Carbon::parse($order->order_datetime, 'America/Sao_Paulo');
                    $orderEnd = $start->copy()->addMinutes((int) ($order->total_duration ?: 30));
                    return $pointer->lt($orderEnd) && $end->gt($start);
                });
                if (! $conflict && (! $date->isSameDay($now) || $pointer->gt($now))) {
                    $available[] = $pointer->format('H:i');
                }
                $pointer->addMinutes(15);
            }
        }
        return response()->json(['available_times' => array_values(array_unique($available))]);
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
        $employer = Employer::findOrFail($data['employer_id']);
        $this->assertEmployerManager($employer);
        $date = Carbon::parse($data['date'], 'America/Sao_Paulo');

        EmployerSchedule::create([
            'employer_id' => $employer->id,
            'day_of_week' => strtolower($date->format('l')),
            'reserved_date' => $date->toDateString(),
            'start_time' => $data['start_time'] ?? '00:00',
            'end_time' => $data['end_time'] ?? '23:59',
            'type' => $data['type'],
            'is_active' => false,
        ]);
        return response()->json(['message' => 'Horário reservado com sucesso.'], 201);
    }

    public function listAppointments(Request $request)
    {
        $data = $request->validate(['employer_id' => 'nullable|integer|exists:employers,id']);
        $employer = isset($data['employer_id']) ? Employer::findOrFail($data['employer_id']) : Employer::where('user_id', Auth::id())->firstOrFail();
        $this->assertEmployerManager($employer);

        $appointments = Order::with(['items.item', 'client:id,first_name,last_name,user_name,phone,email', 'attendant.user:id,first_name,last_name,user_name'])
            ->where('type', 'appointment')->where('attendant_id', $employer->id)->latest('order_datetime')->get();
        return response()->json(['appointments' => $appointments, 'count' => $appointments->count()]);
    }

    public function listMyOrders()
    {
        $employer = Employer::where('user_id', Auth::id())->firstOrFail();
        $orders = Order::with(['items.item', 'client:id,first_name,last_name,user_name,phone,email'])->where('attendant_id', $employer->id)->latest('order_datetime')->get();
        return response()->json(['orders' => $orders, 'count' => $orders->count()]);
    }

    public function checkUpdates(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'last_check' => 'nullable|date',
        ]);
        $employer = Employer::findOrFail($data['employer_id']);
        $this->assertEmployerManager($employer);
        $lastCheck = isset($data['last_check']) ? Carbon::parse($data['last_check']) : now()->subMinutes(10);
        $query = Order::where('attendant_id', $employer->id)->where('type', 'appointment');
        $total = (clone $query)->count();
        $new = (clone $query)->where('created_at', '>', $lastCheck)->count();
        return response()->json(['checked_at' => now()->toDateTimeString(), 'total' => $total, 'new' => $new]);
    }

    private function assertEstablishmentOwner(Establishment $establishment): void
    {
        $user = Auth::user();
        abort_unless($user && ($user->hasProfile('Administrador') || (int) $establishment->user_id === (int) $user->id), 403, 'Apenas o dono pode gerenciar colaboradores.');
    }

    private function assertEmployerManager(Employer $employer): void
    {
        $user = Auth::user();
        $establishment = Establishment::findOrFail($employer->establishment_id);
        abort_unless($user && (
            $user->hasProfile('Administrador')
            || (int) $employer->user_id === (int) $user->id
            || (int) $establishment->user_id === (int) $user->id
        ), 403, 'Você não pode gerenciar este colaborador.');
    }

    private function findEstablishment(string $identifier): Establishment
    {
        return Establishment::query()
            ->where('is_cancelled', false)
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier))
            ->firstOrFail();
    }
}
