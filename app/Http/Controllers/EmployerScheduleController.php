<?php

namespace App\Http\Controllers;

use App\Models\{Employer, EmployerSchedule, Order};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class EmployerScheduleController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'employer_id.required' => 'O campo employer_id é obrigatório.',
            'employer_id.integer' => 'O campo employer_id deve ser um número inteiro.',
            'employer_id.exists' => 'O colaborador informado não existe.',
            'day_of_week.required' => 'O campo dia da semana é obrigatório.',
            'day_of_week.in' => 'O campo dia da semana deve ser um dos valores válidos (monday, tuesday, wednesday, thursday, friday, saturday, sunday).',
            'start_time.required' => 'O campo horário de início é obrigatório.',
            'end_time.required' => 'O campo horário de término é obrigatório.',
        ];
    }

    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
            ], $this->getValidationMessages());

            $schedules = EmployerSchedule::where('employer_id', $data['employer_id'])
                ->orderByRaw("FIELD(day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
                ->orderBy('start_time')
                ->get();

            return response()->json($schedules, 200, [], JSON_UNESCAPED_UNICODE);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('EmployerSchedule.index error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao listar horários.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'schedules' => 'required|array|min:1',
                'schedules.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
                'schedules.*.start_time' => 'required|date_format:H:i',
                'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
            ], $this->getValidationMessages());

            foreach ($data['schedules'] as $schedule) {
                EmployerSchedule::updateOrCreate(
                    [
                        'employer_id' => $data['employer_id'],
                        'day_of_week' => $schedule['day_of_week'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time'],
                    ],
                    ['is_active' => true, 'type' => 'work']
                );
            }

            return response()->json(['message' => 'Horários cadastrados com sucesso.'], 201, [], JSON_UNESCAPED_UNICODE);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('EmployerSchedule.store error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao salvar horários.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    public function destroy($id)
    {
        try {
            $schedule = EmployerSchedule::findOrFail($id);
            $schedule->delete();

            return response()->json(['message' => 'Horário removido com sucesso.'], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('EmployerSchedule.destroy error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao remover horário.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }
public function availableTimes(Request $request)
{
    try {
        $data = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'date' => 'required|date',
            'duration' => 'required|integer|min:5',
        ], [
            'employer_id.required' => 'O campo employer_id é obrigatório.',
            'date.required' => 'O campo data é obrigatório.',
            'date.date' => 'O campo data deve ser uma data válida.',
            'duration.required' => 'O campo duração é obrigatório.',
            'duration.integer' => 'A duração deve ser um número inteiro.',
            'duration.min' => 'A duração mínima é de 5 minutos.',
        ]);

        $employerId = $data['employer_id'];
        $date = Carbon::parse($data['date'])->format('Y-m-d');
        $duration = (int) $data['duration'];
        $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));
        $now = Carbon::now('America/Sao_Paulo');

        // 🔸 Verifica se o dia é feriado
        $isHoliday = EmployerSchedule::where('employer_id', $employerId)
            ->where('type', 'holiday')
            ->whereDate('reserved_date', $date)
            ->exists();

        if ($isHoliday) {
            return response()->json(['available_times' => []], 200, [], JSON_UNESCAPED_UNICODE);
        }

        $schedules = EmployerSchedule::where('employer_id', $employerId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->where('type', 'work')
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json(['available_times' => []], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // 🔹 Busca agendamentos existentes
        $appointments = Order::where('attendant_id', $employerId)
            ->whereDate('order_datetime', $date)
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->get(['order_datetime', 'total_duration']);

        $occupied = [];

        foreach ($appointments as $a) {
            $start = Carbon::parse($a->order_datetime);
            $end = $start->copy()->addMinutes($a->total_duration ?? 0);
            $occupied[] = [$start, $end];
        }

        // 🔸 Inclui pausas (breaks) do mesmo dia
        $breaks = EmployerSchedule::where('employer_id', $employerId)
            ->where('type', 'break')
            ->whereDate('reserved_date', $date)
            ->get();

        foreach ($breaks as $b) {
            $start = Carbon::parse("{$date} {$b->start_time}");
            $end = Carbon::parse("{$date} {$b->end_time}");
            $occupied[] = [$start, $end];
        }

        // Ordena pela hora inicial
        usort($occupied, fn($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);

        $availableTimes = [];

        foreach ($schedules as $schedule) {
            $workStart = Carbon::parse("{$date} {$schedule->start_time}");
            $workEnd = Carbon::parse("{$date} {$schedule->end_time}");
            $step = 15;
            $pointer = $workStart->copy();

            while ($pointer->copy()->addMinutes($duration)->lte($workEnd)) {
                $slotStart = $pointer->copy();
                $slotEnd = $slotStart->copy()->addMinutes($duration);

                // ❌ Ignora horários que já passaram se for hoje
                if ($date === $now->format('Y-m-d') && $slotStart->lt($now)) {
                    $pointer->addMinutes($step);
                    continue;
                }

                // 🔒 Verifica conflito com outros agendamentos
                $hasConflict = false;
                foreach ($occupied as [$occStart, $occEnd]) {
                    if ($slotStart->lt($occEnd) && $slotEnd->gt($occStart)) {
                        $hasConflict = true;
                        break;
                    }
                }

                if (!$hasConflict) {
                    $availableTimes[] = $slotStart->format('H:i');
                }

                $pointer->addMinutes($step);
            }
        }

        $availableTimes = array_values(array_unique($availableTimes));
        sort($availableTimes);

        return response()->json(['available_times' => $availableTimes], 200, [], JSON_UNESCAPED_UNICODE);
    } catch (ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
    } catch (\Exception $e) {
        Log::error('EmployerSchedule.availableTimes error', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Erro ao listar horários disponíveis.'], 500, [], JSON_UNESCAPED_UNICODE);
    }
}

    public function reserve(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'date' => 'required|date',
                'type' => 'required|in:break,holiday',
                'start_time' => 'nullable|date_format:H:i|required_if:type,break',
                'end_time' => 'nullable|date_format:H:i|after:start_time|required_if:type,break',
            ], [
                'employer_id.required' => 'O campo employer_id é obrigatório.',
                'date.required' => 'O campo data é obrigatório.',
                'type.required' => 'O campo tipo é obrigatório.',
                'type.in' => 'O tipo deve ser break (pausa) ou holiday (feriado).',
                'start_time.required_if' => 'O campo horário de início é obrigatório para pausas.',
                'end_time.required_if' => 'O campo horário de término é obrigatório para pausas.',
            ]);

            $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

            EmployerSchedule::create([
                'employer_id' => $data['employer_id'],
                'day_of_week' => $dayOfWeek,
                'reserved_date' => $data['date'],
                'start_time' => $data['start_time'] ?? '00:00',
                'end_time' => $data['end_time'] ?? '23:59',
                'is_active' => false,
                'type' => $data['type'],
            ]);

            return response()->json(['message' => 'Horário reservado com sucesso.'], 201, [], JSON_UNESCAPED_UNICODE);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('EmployerSchedule.reserve error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao reservar horário.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }
}
