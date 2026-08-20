<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SpeakUpMetadataService;
use App\Support\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Speak Up settings (CR §7): metadata capture on/off, the genuinely
 * anonymous route on/off, retention, reveal reason codes, and the NDPA
 * collection notice (versioned — the acknowledgement stored with each
 * capture records which wording the reporter saw).
 *
 * Assigning who may approve reveals is role administration, not a setting:
 * grant 'speak_up.metadata.approve_reveal' on the Roles screen — the
 * platform enforces the permission, not the job title (R1).
 */
class SpeakUpSettingsController extends Controller
{
    public function __construct(private SpeakUpMetadataService $metadata) {}

    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('manage settings'), 403);

        return Inertia::render('Admin/SpeakUp', [
            'settings' => $this->metadata->settings($request->user()->tenant_id),
            'approverRoles' => Role::whereHas('permissions', fn ($q) => $q
                ->where('name', 'speak_up.metadata.approve_reveal'))
                ->orderBy('name')->pluck('name'),
            'recordsOfProcessing' => config('speakup.records_of_processing'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage settings'), 403);

        $validated = $request->validate([
            'metadata_capture' => ['required', 'boolean'],
            'anonymous_mode' => ['required', 'boolean'],
            'retention_months' => ['required', 'integer', 'between:1,120'],
            'reason_codes' => ['required', 'array', 'min:1', 'max:20'],
            'reason_codes.*' => ['required', 'string', 'max:120'],
            'notice_rich' => ['nullable', 'array'],
        ]);

        $tenant = $request->user()->tenant;
        $current = $this->metadata->settings($tenant->id);

        $noticeRich = $validated['notice_rich'] ?? null;
        $noticeChanged = $noticeRich !== null && $noticeRich !== $current['notice_rich'];

        if ($noticeRich !== null) {
            $noticeRich = RichText::isDoc($noticeRich) ? RichText::sanitize($noticeRich) : null;
        }

        $settings = $tenant->settings ?? [];
        $settings['speak_up'] = [
            'metadata_capture' => $validated['metadata_capture'],
            'anonymous_mode' => $validated['anonymous_mode'],
            'retention_months' => $validated['retention_months'],
            'reason_codes' => collect($validated['reason_codes'])
                ->mapWithKeys(fn ($label) => [str($label)->slug('_')->value() => $label])
                ->all(),
            'notice_rich' => $noticeRich ?? $current['notice_rich'],
            'notice_text' => $noticeRich ? RichText::toPlain($noticeRich) : $current['notice_text'],
            // Reporters acknowledge a specific wording; new wording, new
            // version, so an old acknowledgement never vouches for text
            // the reporter did not see.
            'notice_version' => $noticeChanged ? $current['notice_version'] + 1 : $current['notice_version'],
        ];

        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Speak Up settings saved.');
    }
}
