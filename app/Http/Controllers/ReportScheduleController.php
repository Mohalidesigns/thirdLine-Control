<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportScheduleRequest;
use App\Models\ReportDefinition;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\ReportDesignerService;
use App\Support\CronSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class ReportScheduleController extends Controller
{
    public function __construct(private ReportDesignerService $designer) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportSchedule::class);

        return Inertia::render('Reports/Schedules', [
            'schedules' => ReportSchedule::query()
                ->with(['definition:id,code,name,confidentiality', 'creator:id,name'])
                ->withCount('runs')
                ->orderBy('name')
                ->get(),
            'definitions' => ReportDefinition::active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'output_formats', 'confidentiality']),
            'deliveryMethods' => ReportSchedule::DELIVERY_METHODS,
            'formats' => $this->designer->formats(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'presets' => CronSchedule::PRESETS,
        ]);
    }

    public function store(ReportScheduleRequest $request): RedirectResponse
    {
        $this->authorize('create', ReportSchedule::class);

        $data = $request->validated();

        $schedule = ReportSchedule::create($data + [
            'created_by' => $request->user()->id,
            'next_run_at' => CronSchedule::nextRun($data['cron'], $data['timezone']),
        ]);

        $schedule->auditAction('report_schedule.created', null, ['name' => $schedule->name]);

        return back()->with('success', "'{$schedule->name}' scheduled. Next run "
            .$schedule->next_run_at?->format('d M Y H:i').'.');
    }

    public function update(ReportScheduleRequest $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $data = $request->validated();

        $schedule->update($data + [
            'next_run_at' => CronSchedule::nextRun($data['cron'], $data['timezone']),
        ]);

        return back()->with('success', 'Schedule updated.');
    }

    public function destroy(ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->auditAction('report_schedule.deleted', ['name' => $schedule->name], null);
        $schedule->delete();

        return back()->with('success', 'Schedule removed.');
    }

    /** Run a schedule now — the same path the scheduler takes, on demand. */
    public function runNow(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('run', $schedule);

        $run = $this->designer->run(
            $schedule->definition,
            $request->user(),
            $schedule->output_format,
            $schedule->parameters ?? [],
            $schedule,
        );

        $delivered = $this->designer->distribute($run, $schedule);

        $schedule->update([
            'last_run_at' => now(),
            'last_status' => $run->status,
            'next_run_at' => CronSchedule::nextRun($schedule->cron, $schedule->timezone),
        ]);

        return back()->with(
            $run->status === 'Completed' ? 'success' : 'error',
            $run->status === 'Completed'
                ? "{$run->run_ref} generated and distributed to ".count($delivered).' recipient(s).'
                : "Generation failed: {$run->error_message}",
        );
    }
}
