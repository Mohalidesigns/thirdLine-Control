<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantSecurityRequest;
use App\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class TenantSecurityController extends Controller
{
    public function __construct(private MfaService $mfa) {}

    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('manage settings'), 403);

        $tenant = $request->user()->tenant;

        return Inertia::render('Admin/Security', [
            'policy' => [
                'mfa_enforced_roles' => $tenant->mfa_enforced_roles ?? [],
                'mfa_grace_period_days' => $tenant->mfa_grace_period_days,
                'mfa_sms_opt_in' => $tenant->mfa_sms_opt_in,
                'break_glass_daily_cap' => $tenant->break_glass_daily_cap,
                'audit_retention_months' => $tenant->settings['audit_retention_months'] ?? null,
                'audit_retention_default' => config('audit.retention_months'),
            ],
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(TenantSecurityRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $tenant = $request->user()->tenant;

        $this->mfa->updatePolicy(
            $tenant,
            $data['mfa_enforced_roles'],
            $data['mfa_grace_period_days'],
            $data['mfa_sms_opt_in'],
        );

        $settings = $tenant->settings ?? [];
        if (($data['audit_retention_months'] ?? null) !== null) {
            $settings['audit_retention_months'] = (int) $data['audit_retention_months'];
        } else {
            unset($settings['audit_retention_months']);
        }

        $tenant->forceFill([
            'break_glass_daily_cap' => $data['break_glass_daily_cap'],
            'settings' => $settings,
        ])->save();

        return back()->with('success', 'Security policy updated.');
    }
}
