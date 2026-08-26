<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\AuditEventCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Activity Log (CR3): every user action on the application —
 * logins, CRUD, workflow transitions. Server-side filtering, cursor
 * pagination, CSV/PDF evidence-pack export (itself logged).
 */
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

    private const FILTER_KEYS = ['search', 'event', 'user_id', 'entity_type', 'from', 'to'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('view audit log'), 403);

        $entries = $this->filteredQuery($request)
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString()
            ->through(fn ($entry) => $this->present($entry));

        return Inertia::render('Settings/ActivityLog/Index', [
            'entries' => $entries,
            'filters' => $request->only(self::FILTER_KEYS),
            'options' => $this->filterOptions($request),
            'canExport' => $request->user()->can('export audit log'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('export audit log'), 403);

        // The export itself is an auditable act (R3).
        app(AuditTrailService::class)->record('audit_log_exported', $request->user(), null, [
            'format' => 'csv',
            'filters' => array_filter($request->only(self::FILTER_KEYS)),
        ]);

        $query = $this->filteredQuery($request)->with('user:id,name')->orderByDesc('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'ID', 'Timestamp', 'Actor', 'Actor Email', 'Event', 'Event Label',
                'Subject Type', 'Subject ID', 'Subject', 'Description',
                'Method', 'URL', 'Status', 'IP', 'Device', 'Batch', 'Before', 'After',
            ]);

            $query->chunk(500, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->id,
                        $entry->created_at->toIso8601String(),
                        $entry->actor_name ?? $entry->user?->name ?? 'System',
                        $entry->actor_email,
                        $entry->action,
                        $entry->event_label ?? AuditEventCatalog::label($entry->action),
                        $entry->entity_type,
                        $entry->entity_id,
                        $entry->subject_label,
                        $entry->description,
                        $entry->method,
                        $entry->url,
                        $entry->status_code,
                        $entry->ip_address,
                        $entry->device_name,
                        $entry->batch_id,
                        json_encode($entry->before),
                        json_encode($entry->after),
                    ]);
                }
            });

            fclose($out);
        }, 'activity-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Evidence-pack PDF: the applied filters are printed in the header so
     * the pack is self-describing. Capped — a full-table dump belongs in
     * the CSV export, not a PDF.
     */
    public function exportPdf(Request $request)
    {
        abort_unless($request->user()->can('export audit log'), 403);

        app(AuditTrailService::class)->record('audit_log_exported', $request->user(), null, [
            'format' => 'pdf',
            'filters' => array_filter($request->only(self::FILTER_KEYS)),
        ]);

        $cap = 1000;
        $query = $this->filteredQuery($request)->with('user:id,name')->orderByDesc('id');
        $total = (clone $query)->count();
        $entries = $query->limit($cap)->get()->map(fn ($e) => $this->present($e));

        return Pdf::loadView('exports.activity-log', [
            'entries' => $entries,
            'filters' => array_filter($request->only(self::FILTER_KEYS)),
            'total' => $total,
            'cap' => $cap,
            'generatedBy' => $request->user()->name,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')
            ->download('activity-log-'.now()->format('Ymd-His').'.pdf');
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
                ->whereIn('entity_type', AuditTrail::storedTypesFor($class))
                ->where('entity_id', $id)
                ->with('user:id,name')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AuditTrail $entry): array
    {
        $actorName = $entry->actor_name ?? $entry->user?->name;
        $actorEmail = $entry->actor_email ?? $entry->user?->email;

        return [
            'id' => $entry->id,
            'event' => $entry->action,
            'event_label' => $entry->event_label ?? AuditEventCatalog::label($entry->action),
            'badge' => AuditEventCatalog::badgeClass($entry->action),
            'user' => $actorName ? ['name' => $actorName, 'email' => $actorEmail] : null,
            'subject_type' => $this->presentableType($entry->entity_type),
            'subject_id' => $entry->entity_id,
            'subject_label' => $entry->subject_label,
            'description' => $entry->description,
            'before' => $entry->before,
            'after' => $entry->after,
            'method' => $entry->method,
            'url' => $entry->url,
            'route_name' => $entry->route_name,
            'status_code' => $entry->status_code,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'device_name' => $entry->device_name,
            'batch_id' => $entry->batch_id,
            'created_at' => $entry->created_at,
        ];
    }

    /** "App\Models\Control" → "Control"; topic strings pass through. */
    private function presentableType(?string $type): ?string
    {
        if ($type === null || in_array($type, ['auth', 'system', 'request', 'route'], true)) {
            return null;
        }

        // A row written before a class was renamed is shown under the name
        // the class has now. The stored value is untouched — audit rows are
        // immutable — so this is presentation, not revision.
        return class_basename(AuditTrail::canonicalType($type));
    }

    private function filterOptions(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'events' => AuditTrail::query()
                ->where('tenant_id', $tenantId)
                ->select('action')->distinct()->orderBy('action')->pluck('action')
                ->map(fn ($a) => ['value' => $a, 'label' => AuditEventCatalog::label($a)])
                ->values(),
            'users' => User::tenantPicker()->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            // Renamed classes collapse to one option. Offering "Case" twice
            // — once per historical spelling, each finding half the rows —
            // is worse than not offering the filter at all.
            'entity_types' => AuditTrail::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('entity_type')
                ->select('entity_type')->distinct()->pluck('entity_type')
                ->map(fn ($t) => AuditTrail::canonicalType($t))
                ->unique()
                ->sort()
                ->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])
                ->values(),
        ];
    }

    private function filteredQuery(Request $request)
    {
        return AuditTrail::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->date('from'), fn ($q, $from) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()))
            ->when($request->input('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->input('event'), fn ($q, $event) => $q->where('action', $event))
            ->when(
                $request->input('entity_type'),
                fn ($q, $type) => $q->whereIn('entity_type', AuditTrail::storedTypesFor($type)),
            )
            ->when($request->input('entity_id'), fn ($q, $id) => $q->where('entity_id', $id))
            ->when($request->input('ip'), fn ($q, $ip) => $q->where('ip_address', $ip))
            ->when($request->input('search'), fn ($q, $search) => $q->where(function ($w) use ($search) {
                $w->where('description', 'like', "%{$search}%")
                    ->orWhere('actor_name', 'like', "%{$search}%")
                    ->orWhere('actor_email', 'like', "%{$search}%")
                    ->orWhere('subject_label', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%");
            }));
    }
}
