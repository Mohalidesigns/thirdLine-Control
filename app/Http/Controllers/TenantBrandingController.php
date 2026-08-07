<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantBrandingRequest;
use App\Models\TenantBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantBrandingController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('manage settings'), 403);

        $branding = TenantBranding::firstWhere('tenant_id', $request->user()->tenant_id);

        return Inertia::render('Admin/Branding', [
            'branding' => $branding ? [
                ...$branding->only([
                    'primary_colour', 'accent_colour', 'product_name', 'support_email',
                    'support_phone', 'report_header_html', 'report_footer_html', 'email_from_name',
                ]),
                ...$branding->toSharedProps(),
            ] : null,
        ]);
    }

    public function update(TenantBrandingRequest $request): RedirectResponse
    {
        $data = collect($request->validated())
            ->except(['logo', 'logo_dark', 'favicon', 'login_background'])
            ->all();

        foreach ([
            'logo' => 'logo_path',
            'logo_dark' => 'logo_dark_path',
            'favicon' => 'favicon_path',
            'login_background' => 'login_background_path',
        ] as $input => $column) {
            if ($request->hasFile($input)) {
                $data[$column] = $request->file($input)->store('branding', 'public');
            }
        }

        $branding = TenantBranding::updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id],
            $data,
        );

        $branding->auditAction('branding_updated', null, collect($data)->except(['report_header_html', 'report_footer_html'])->all());

        return back()->with('success', 'Branding updated.');
    }
}
