<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Models\IntegrationSyncLog;
use App\Services\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(private IntegrationService $integrationService) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Integrations', [
            'configs' => IntegrationConfig::query()->get(),
            'syncLogs' => IntegrationSyncLog::query()
                ->with('config:id,target_system')
                ->latest()
                ->limit(50)
                ->get(),
            'newKey' => session('newKey'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_system' => ['required', Rule::in(['ThirdLine', 'NexusRisk'])],
            'base_url' => ['nullable', 'url'],
            'outbound_key' => ['nullable', 'string', 'max:255'],
            'sync_direction' => ['required', Rule::in(['Push', 'Pull', 'Bidirectional'])],
            'master_system' => ['required', Rule::in(['SecondLine', 'ThirdLine', 'PerCategory'])],
            'is_active' => ['boolean'],
        ]);

        // A fresh inbound key is generated on save and shown exactly once.
        $inboundKey = 'slk_'.Str::random(40);

        $config = IntegrationConfig::updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'target_system' => $validated['target_system']],
            [
                'base_url' => $validated['base_url'] ?? null,
                'sync_direction' => $validated['sync_direction'],
                'master_system' => $validated['master_system'],
                'is_active' => $validated['is_active'] ?? false,
                'inbound_key_hash' => hash('sha256', $inboundKey),
            ],
        );

        if (! empty($validated['outbound_key'])) {
            $config->credentials = $validated['outbound_key'];
            $config->save();
        }

        return back()
            ->with('success', 'Integration saved — copy the inbound API key now; it is not shown again.')
            ->with('newKey', $inboundKey);
    }

    public function replay(Request $request, IntegrationSyncLog $syncLog): RedirectResponse
    {
        abort_unless($syncLog->status === 'Failed' && $syncLog->direction === 'outbound', 422);

        $replayed = $this->integrationService->replay($syncLog);

        return back()->with(
            $replayed->status === 'Success' ? 'success' : 'error',
            $replayed->status === 'Success' ? 'Sync replayed successfully.' : 'Replay failed: '.$replayed->error_message,
        );
    }
}
