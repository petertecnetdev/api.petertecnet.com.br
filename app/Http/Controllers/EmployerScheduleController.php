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
                    ['is_active' => true]
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
            ], [
                'employer_id.required' => 'O campo employer_id é obrigatório.',
                'date.required' => 'O campo data é obrigatório.',
                'date.date' => 'O campo data deve ser uma data válida.',
            ]);

            $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

            $schedules = EmployerSchedule::where('employer_id', $data['employer_id'])
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->get();

            if ($schedules->isEmpty()) {
                return response()->json(['available_times' => []], 200, [], JSON_UNESCAPED_UNICODE);
            }

            $occupiedTimes = Order::where('collaborator_id', $data['employer_id'])
                ->whereDate('scheduled_datetime', $data['date'])
                ->pluck('scheduled_datetime')
                ->map(fn($t) => Carbon::parse($t)->format('H:i'))
                ->toArray();

            $availableTimes = [];

            foreach ($schedules as $schedule) {
                $start = Carbon::parse($schedule->start_time);
                $end = Carbon::parse($schedule->end_time);

                while ($start < $end) {
                    $time = $start->format('H:i');
                    if (!in_array($time, $occupiedTimes)) {
                        $availableTimes[] = $time;
                    }
                    $start->addMinutes(30);
                }
            }

            return response()->json(['available_times' => $availableTimes], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('EmployerSchedule.availableTimes error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao listar horários disponíveis.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }
}
