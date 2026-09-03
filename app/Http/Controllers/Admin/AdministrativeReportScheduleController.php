<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeReportSchedule;
use App\Services\Reporting\AdministrativeReportAccessService;
use App\Services\Reporting\AdministrativeReportService;
use App\Services\Reporting\ReportRendererRegistry;
use App\Services\Reporting\ReportScheduleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdministrativeReportScheduleController extends Controller
{
    public function __construct(
        private AdministrativeReportAccessService $access,
        private AdministrativeReportService $reports,
        private ReportRendererRegistry $renderers,
        private ReportScheduleService $schedules,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertManage($request->user());
        return response()->json(['schedules' => AdministrativeReportSchedule::query()->where('user_id', $request->user()->id)->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->access->assertManage($request->user());
        $data = $this->validateData($request);
        $this->access->assertManage($request->user(), $data['report_key']);
        $schedule = new AdministrativeReportSchedule($data);
        $schedule->user_id = $request->user()->id;
        $schedule->save();
        $schedule->next_run_at = $this->schedules->nextRun($schedule);
        $schedule->save();
        return response()->json(['schedule' => $schedule], 201);
    }

    public function update(Request $request, AdministrativeReportSchedule $schedule)
    {
        $this->access->assertManage($request->user());
        abort_unless((int) $schedule->user_id === (int) $request->user()->id || $request->user()->hasProfile('Administrador'), 403);
        $data = $this->validateData($request, false);
        if (isset($data['report_key'])) $this->access->assertManage($request->user(), $data['report_key']);
        $schedule->fill($data)->save();
        $schedule->next_run_at = $schedule->is_active ? $this->schedules->nextRun($schedule) : null;
        $schedule->save();
        return response()->json(['schedule' => $schedule]);
    }

    public function destroy(Request $request, AdministrativeReportSchedule $schedule)
    {
        $this->access->assertManage($request->user());
        abort_unless((int) $schedule->user_id === (int) $request->user()->id || $request->user()->hasProfile('Administrador'), 403);
        $schedule->delete();
        return response()->json(null, 204);
    }

    private function validateData(Request $request, bool $creating = true): array
    {
        $rule = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$rule, 'string', 'max:150'],
            'report_key' => [$rule, 'string', 'max:80'],
            'format' => [$rule, Rule::in($this->renderers->formats())],
            'cadence' => [$rule, Rule::in(['daily', 'weekly', 'monthly'])],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'hour' => [$rule, 'integer', 'min:0', 'max:23'],
            'minute' => [$rule, 'integer', 'min:0', 'max:59'],
            'timezone' => ['nullable', 'timezone'],
            'filters' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['report_key'])) abort_unless(collect($this->reports->definitions())->pluck('key')->contains($data['report_key']), 422, 'Tipo de relatório não encontrado.');
        return $data;
    }
}
