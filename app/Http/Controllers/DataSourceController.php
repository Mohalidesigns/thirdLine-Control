<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataSourceDatasetRequest;
use App\Http\Requests\DataSourceRequest;
use App\Integrations\ConnectorRegistry;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\IntegrationSyncLog;
use App\Models\User;
use App\Services\ConnectorManager;
use App\Services\SnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data source administration (12.1).
 *
 * Nothing this controller returns to the browser contains a credential or
 * a connection string: DataSource hides both, and the dataset projection
 * below is built by hand rather than by toArray() so an extraction_config
 * holding a query never reaches an Inertia prop (R9).
 */
class DataSourceController extends Controller
{
    public function __construct(
        private ConnectorManager $connectors,
        private SnapshotService $snapshots,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DataSource::class);

        $sources = DataSource::query()
            ->with(['owner:id,name', 'approver:id,name'])
            ->withCount('datasets')
            ->when($request->health_status, fn ($q, $h) => $q->where('health_status', $h))
            ->when($request->system_category, fn ($q, $c) => $q->where('system_category', $c))
            ->when($request->vendor_key, fn ($q, $v) => $q->where('vendor_key', $v))
            ->when($request->boolean('pending_approval'), fn ($q) => $q->whereNull('approved_at'))
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/DataSources/Index', [
            'sources' => $sources->through(fn (DataSource $dataSource) => [
                ...$dataSource->toArray(),
                'verified_connector' => ConnectorRegistry::isVerified($dataSource->vendor_key, $dataSource->source_type),
            ]),
            'filters' => $request->only(['health_status', 'system_category', 'vendor_key', 'pending_approval', 'search']),
            'options' => [
                'source_types' => DataSource::SOURCE_TYPES,
                'system_categories' => DataSource::SYSTEM_CATEGORIES,
                'vendor_keys' => DataSource::VENDOR_KEYS,
                'auth_types' => DataSource::AUTH_TYPES,
                'health_statuses' => DataSource::HEALTH_STATUSES,
            ],
            'capabilities' => ConnectorRegistry::matrix(),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => DataSource::count(),
                'healthy' => DataSource::where('health_status', 'Healthy')->count(),
                'failed' => DataSource::where('health_status', 'Failed')->count(),
                'awaiting_approval' => DataSource::whereNull('approved_at')->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage data-sources'),
                'approve' => $request->user()->can('approve data-sources'),
            ],
        ]);
    }

    public function store(DataSourceRequest $request): RedirectResponse
    {
        $this->authorize('create', DataSource::class);

        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $data['credentials_ref'] = 'data_source:'.$request->user()->tenant_id.':'.md5($data['name']);

        // A source is never born approved, and never born active.
        $data['is_active'] = false;

        $dataSource = DataSource::create($data);

        return redirect()
            ->route('admin.data-sources.show', $dataSource)
            ->with('success', "Data source {$dataSource->name} registered. It needs approval before it will extract.");
    }

    public function show(Request $request, DataSource $dataSource): Response
    {
        $this->authorize('view', $dataSource);

        $dataSource->load(['owner:id,name', 'creator:id,name', 'approver:id,name']);
        $capabilities = ConnectorRegistry::capabilitiesFor($dataSource->vendor_key, $dataSource->source_type);

        return Inertia::render('Admin/DataSources/Show', [
            'source' => $dataSource,
            'capabilities' => $capabilities->toArray(),
            'residency' => [
                'country' => $this->snapshots->residencyFor($dataSource->tenant_id),
                'note' => $dataSource->data_residency_note,
            ],
            'datasets' => $dataSource->datasets()
                ->with('dpoAuthoriser:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (DataSourceDataset $dataset) => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                    'description' => $dataset->description,
                    'primary_key_fields' => $dataset->primary_key_fields,
                    'incremental_field' => $dataset->incremental_field,
                    'retention_days' => $dataset->retention_days,
                    'row_count_last' => $dataset->row_count_last,
                    'pii_classification' => $dataset->pii_classification,
                    'pii_fields' => $dataset->pii_fields,
                    'requires_hashing' => $dataset->requiresHashing(),
                    'dpo_authorised_at' => $dataset->dpo_authorised_at,
                    'dpo_authoriser' => $dataset->dpoAuthoriser?->name,
                    'is_active' => $dataset->is_active,
                    // Deliberately projected: extraction_config can carry a
                    // query or a path that describes the source's internals.
                    'extraction_summary' => $this->extractionSummary($dataset),
                ]),
            'snapshots' => DataSnapshot::query()
                ->whereIn('dataset_id', $dataSource->datasets()->pluck('id'))
                ->with('dataset:id,name')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'dataset_id', 'snapshot_ref', 'period_label', 'captured_at', 'row_count', 'size_bytes', 'status', 'legal_hold', 'expires_at', 'error_message']),
            'syncLogs' => IntegrationSyncLog::query()
                ->where('entity_type', 'like', 'ccm:%')
                ->where('local_id', $dataSource->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'entity_type', 'status', 'error_message', 'payload', 'attempted_at']),
            'can' => [
                'manage' => $request->user()->can('update', $dataSource),
                'approve' => $request->user()->can('approve', $dataSource),
                'test' => $request->user()->can('test', $dataSource),
                'authorise_pii' => $request->user()->can('authoriseRetention', $dataSource),
            ],
        ]);
    }

    public function update(DataSourceRequest $request, DataSource $dataSource): RedirectResponse
    {
        $this->authorize('update', $dataSource);

        $data = $request->validated();

        // A blank credential field means "leave the stored secret alone".
        if (blank($data['credentials'] ?? null)) {
            unset($data['credentials']);
        }

        $dataSource->update($data);

        return back()->with('success', "Data source {$dataSource->name} updated.");
    }

    /**
     * Approval into production. Maker ≠ checker is enforced by the policy;
     * the source only becomes extractable at this moment.
     */
    public function approve(Request $request, DataSource $dataSource): RedirectResponse
    {
        $this->authorize('approve', $dataSource);

        $dataSource->update([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'is_active' => true,
        ]);

        $dataSource->auditAction('approved', null, ['by' => $request->user()->id]);

        return back()->with('success', "{$dataSource->name} approved and activated.");
    }

    public function test(Request $request, DataSource $dataSource): RedirectResponse
    {
        $this->authorize('test', $dataSource);

        $result = $this->connectors->test($dataSource);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'].' ('.$result['latency_ms'].'ms)',
        );
    }

    /** Reopening a tripped circuit breaker is deliberate and audited. */
    public function resume(Request $request, DataSource $dataSource): RedirectResponse
    {
        $this->authorize('resume', $dataSource);

        $this->connectors->resume($dataSource, $request->user());

        return back()->with('success', "{$dataSource->name} resumed. It will extract on the next sweep.");
    }

    public function storeDataset(DataSourceDatasetRequest $request, DataSource $dataSource): RedirectResponse
    {
        $this->authorize('update', $dataSource);

        $dataSource->datasets()->create([
            ...$request->validated(),
            'tenant_id' => $dataSource->tenant_id,
        ]);

        return back()->with('success', 'Dataset added.');
    }

    public function updateDataset(DataSourceDatasetRequest $request, DataSource $dataSource, DataSourceDataset $dataset): RedirectResponse
    {
        $this->authorize('update', $dataSource);
        abort_unless($dataset->data_source_id === $dataSource->id, 404);

        // Reclassifying downwards would silently expose data captured
        // under a stricter promise, so it clears any DPO authorisation and
        // makes the next capture re-decide.
        $data = $request->validated();

        if ($data['pii_classification'] !== $dataset->pii_classification) {
            $data['dpo_authorised_by'] = null;
            $data['dpo_authorised_at'] = null;
            $data['dpo_authorisation_note'] = null;
        }

        $dataset->update($data);

        return back()->with('success', 'Dataset updated.');
    }

    /**
     * The DPO's explicit authorisation to retain sensitive personal data
     * in clear (12.7). Without it the declared fields are hashed at
     * ingestion and nothing can undo that.
     */
    public function authoriseRetention(Request $request, DataSource $dataSource, DataSourceDataset $dataset): RedirectResponse
    {
        $this->authorize('authoriseRetention', $dataSource);
        abort_unless($dataset->data_source_id === $dataSource->id, 404);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $dataset->update([
            'dpo_authorised_by' => $request->user()->id,
            'dpo_authorised_at' => now(),
            'dpo_authorisation_note' => $validated['note'],
        ]);

        $dataset->auditAction('pii_retention_authorised', null, [
            'by' => $request->user()->id,
            'classification' => $dataset->pii_classification,
        ]);

        return back()->with('success', 'Retention authorised. Future captures will retain the declared fields in clear.');
    }

    public function revokeRetention(Request $request, DataSource $dataSource, DataSourceDataset $dataset): RedirectResponse
    {
        $this->authorize('authoriseRetention', $dataSource);
        abort_unless($dataset->data_source_id === $dataSource->id, 404);

        $dataset->update([
            'dpo_authorised_by' => null,
            'dpo_authorised_at' => null,
            'dpo_authorisation_note' => null,
        ]);

        $dataset->auditAction('pii_retention_revoked', null, ['by' => $request->user()->id]);

        return back()->with('success', 'Authorisation revoked. Future captures will hash the declared fields.');
    }

    /** Pull one dataset on demand, for a source that has just been fixed. */
    public function capture(Request $request, DataSource $dataSource, DataSourceDataset $dataset): RedirectResponse
    {
        $this->authorize('update', $dataSource);
        abort_unless($dataset->data_source_id === $dataSource->id, 404);

        try {
            $snapshot = $this->snapshots->capture($dataset);
        } catch (\Throwable $e) {
            return back()->with('error', 'Extraction failed: '.$e->getMessage());
        }

        return back()->with(
            'success',
            "Captured {$snapshot->row_count} row(s) as {$snapshot->snapshot_ref}.",
        );
    }

    /** A description of how a dataset is read, without revealing the query. */
    private function extractionSummary(DataSourceDataset $dataset): string
    {
        $config = $dataset->extraction_config ?? [];

        return match (true) {
            isset($config['endpoint']) => 'Endpoint '.$config['endpoint'],
            isset($config['table']) => 'Table '.$config['table'],
            isset($config['pattern']) => 'File pattern '.$config['pattern'],
            isset($config['upload_path']) => 'Uploaded workbook',
            isset($config['query']) => 'Custom query ('.strlen((string) $config['query']).' characters)',
            default => 'Not configured',
        };
    }
}
