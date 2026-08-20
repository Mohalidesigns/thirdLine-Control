<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /**
     * Record types the audit explorer can inspect inline. Aliases keep
     * morph class names out of URLs.
     */
    private const ENTITY_ALIASES = [
        'control' => Control::class,
        'exception' => ControlException::class,
        'test-instance' => TestInstance::class,
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('view audit log'), 403);

        $entries = $this->filteredQuery($request)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        return Inertia::render('Admin/AuditLog', [
            'entries' => $entries,
            'filters' => $request->only(['from', 'to', 'user_id', 'entity_type', 'entity_id', 'action', 'ip']),
            'entityTypes' => AuditTrail::where('tenant_id', $request->user()->tenant_id)
                ->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'canExport' => $request->user()->can('export audit log'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('export audit log'), 403);

        // The export itself is an auditable act (R3).
        app(AuditTrailService::class)->record('audit_log_exported', $request->user(), null, [
            'filters' => $request->only(['from', 'to', 'user_id', 'entity_type', 'entity_id', 'action', 'ip']),
        ]);

        $query = $this->filteredQuery($request)->with('user:id,name')->orderByDesc('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Timestamp', 'User', 'Entity Type', 'Entity ID', 'Action', 'IP', 'Before', 'After']);

            $query->chunk(500, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->id,
                        $entry->created_at->toIso8601String(),
                        $entry->user?->name ?? 'System',
                        $entry->entity_type,
                        $entry->entity_id,
                        $entry->action,
                        $entry->ip_address,
                        json_encode($entry->before),
                        json_encode($entry->after),
                    ]);
                }
            });

            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A single record's audit history, for the Activity tab on show pages.
     * Access mirrors the record's own view policy — never wider.
     */
    public function entity(Request $request, string $alias, int $id)
    {
        $class = self::ENTITY_ALIASES[$alias] ?? abort(404);

        $record = $class::findOrFail($id);

        Gate::authorize('view', $record);

        return response()->json([
            'entries' => AuditTrail::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->where('entity_type', $class)
                ->where('entity_id', $id)
                ->with('user:id,name')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }

    private function filteredQuery(Request $request)
    {
        return AuditTrail::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->date('from'), fn ($q, $from) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()))
            ->when($request->input('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->input('entity_type'), fn ($q, $type) => $q->where('entity_type', $type))
            ->when($request->input('entity_id'), fn ($q, $id) => $q->where('entity_id', $id))
            ->when($request->input('action'), fn ($q, $action) => $q->where('action', 'like', "%{$action}%"))
            ->when($request->input('ip'), fn ($q, $ip) => $q->where('ip_address', $ip));
    }
}
