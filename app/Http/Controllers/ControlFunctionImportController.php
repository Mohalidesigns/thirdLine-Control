<?php

namespace App\Http\Controllers;

use App\Models\ControlFunctionImport;
use App\Services\ControlFunctionImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR-03 §D: upload → dry-run diff → commit. The two steps are separate
 * on purpose: one commit rewrites 167 controls and 1,517 checklist lines,
 * and nobody should discover what it changed afterwards.
 *
 * An unresolved frequency blocks the commit rather than defaulting — the
 * failure this whole change request exists to prevent is a Daily control
 * quietly rescheduled as Monthly.
 */
class ControlFunctionImportController extends Controller
{
    public function __construct(private ControlFunctionImportService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('control-functions.import');

        return Inertia::render('ControlFunctions/Import', [
            'imports' => ControlFunctionImport::query()
                ->with('creator:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ControlFunctionImport $import) => $this->summarise($import)),
            'pending' => $request->pending
                ? $this->detail(ControlFunctionImport::query()->findOrFail($request->pending))
                : null,
            'sheets' => array_keys(ControlFunctionImportService::SHEETS),
        ]);
    }

    /** Upload and stage. Writes the staging tables and nothing else. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('control-functions.import');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        $path = $data['file']->getRealPath();

        $import = $this->service->dryRun(
            $this->service->rowsFromSpreadsheet($path),
            [
                'source_name' => $request->file('file')->getClientOriginalName(),
                'source_hash' => hash_file('sha256', $path),
            ],
            $request->user()->tenant_id,
            $request->user(),
        );

        return redirect()
            ->route('control-functions.import.index', ['pending' => $import->id])
            ->with('success', sprintf('Staged %d row(s). Review the diff before committing.', $import->rows_total));
    }

    public function commit(Request $request, ControlFunctionImport $controlFunctionImport): RedirectResponse
    {
        Gate::authorize('control-functions.import');

        $committed = $this->service->commit($controlFunctionImport, $request->user());

        return redirect()
            ->route('control-functions.import.index')
            ->with('success', sprintf(
                '%s committed: %d function(s) added, %d changed, %d checklist(s) versioned to draft.',
                $committed->reference,
                $committed->controls_added,
                $committed->controls_changed,
                $committed->scripts_versioned,
            ));
    }

    public function discard(ControlFunctionImport $controlFunctionImport): RedirectResponse
    {
        Gate::authorize('control-functions.import');

        $controlFunctionImport->forceFill(['status' => 'Discarded'])->save();
        $controlFunctionImport->auditAction('control-function-import-discarded');

        return redirect()
            ->route('control-functions.import.index')
            ->with('success', "{$controlFunctionImport->reference} discarded — nothing was written to the control library.");
    }

    private function summarise(ControlFunctionImport $import): array
    {
        return [
            'id' => $import->id,
            'reference' => $import->reference,
            'source_name' => $import->source_name,
            'status' => $import->status,
            'rows_total' => $import->rows_total,
            'rows_unresolved' => $import->rows_unresolved,
            'controls_added' => $import->controls_added,
            'controls_changed' => $import->controls_changed,
            'scripts_versioned' => $import->scripts_versioned,
            'created_by' => $import->creator?->name,
            'created_at' => $import->created_at?->toDateTimeString(),
            'committed_at' => $import->committed_at?->toDateTimeString(),
            'is_committable' => $import->isCommittable(),
        ];
    }

    private function detail(ControlFunctionImport $import): array
    {
        return [
            ...$this->summarise($import),
            'diff' => $import->diff_report ?? [],
            'error' => $import->error,
            // Only the rows that block the commit — a reviewer does not
            // need 1,517 unchanged lines rendered at them.
            'unresolved_rows' => $import->rows()->unresolved()->orderBy('row_no')->limit(200)->get()
                ->map(fn ($row) => [
                    'row_no' => $row->row_no,
                    'sheet' => $row->sheet,
                    'source_ref' => $row->source_ref,
                    'unit' => $row->unit_raw,
                    'function' => $row->function_raw,
                    'frequency_raw' => $row->frequency_raw,
                    'message' => $row->message,
                ])->values(),
        ];
    }
}
