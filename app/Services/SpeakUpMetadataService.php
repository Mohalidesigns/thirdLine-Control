<?php

namespace App\Services;

use App\Models\SpeakUpCase;
use App\Models\SpeakUpMetadataAccessLog;
use App\Models\SpeakUpReportMetadata;
use App\Models\SpeakUpRevealRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\IpIntelligence;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Speak Up reporter technical metadata (CR).
 *
 * The rules this service exists to keep:
 *
 *   1. **The anonymous route captures nothing.** capture() refuses an
 *      anonymous case outright — the metadata layer applies only to the
 *      disclosed, acknowledged "confidential" route.
 *   2. **Tier 2 is a break-glass act.** Identifying fields (IP, forwarded
 *      chain, hostname, ISP/ASN, city, linked staff identity) open only on
 *      a reason-coded, justified request approved by a second person, and
 *      every view writes the immutable Metadata Access Log.
 *   3. **Nothing is fabricated.** A value the platform did not obtain is
 *      stored as null with a source flag saying why.
 *   4. **Signals are decision support, not evidence.** Tier 1 shows
 *      correlation and anomaly indicators; the UI states that a signal
 *      never substitutes for assessing a report on its merits.
 */
class SpeakUpMetadataService
{
    // ── Tenant settings ──────────────────────────────────────────────────

    /**
     * Effective Speak Up settings for a tenant: stored overrides on top of
     * config('speakup.defaults'). Notice text defaults to null — the form
     * renders its built-in wording until an admin authors one.
     *
     * @return array<string, mixed>
     */
    public function settings(?int $tenantId): array
    {
        $stored = $tenantId
            ? (Tenant::find($tenantId)?->settings['speak_up'] ?? [])
            : [];

        $defaults = config('speakup.defaults');

        return [
            'metadata_capture' => (bool) ($stored['metadata_capture'] ?? $defaults['metadata_capture']),
            'anonymous_mode' => (bool) ($stored['anonymous_mode'] ?? $defaults['anonymous_mode']),
            'retention_months' => (int) ($stored['retention_months'] ?? $defaults['retention_months']),
            'reason_codes' => $stored['reason_codes'] ?? $defaults['reason_codes'],
            'notice_version' => (int) ($stored['notice_version'] ?? 1),
            'notice_rich' => $stored['notice_rich'] ?? null,
            'notice_text' => $stored['notice_text'] ?? null,
        ];
    }

    // ── Capture ──────────────────────────────────────────────────────────

    /**
     * Capture the technical metadata for a confidential submission.
     * Server-observed values first; the client payload fills only what the
     * server cannot see, validated and truncated, never trusted alone.
     *
     * @param  array<string, mixed>  $clientMeta
     */
    public function capture(
        SpeakUpCase $case,
        Request $request,
        array $clientMeta,
        ?int $sessionDurationSeconds,
        int $noticeVersion,
    ): ?SpeakUpReportMetadata {
        // Invariant 1: an anonymous case stores nothing that identifies
        // the reporter — belt and braces on top of the controller's own
        // routing, because this is the line the whole channel stands on.
        if ($case->is_anonymous) {
            return null;
        }

        $settings = $this->settings($case->tenant_id);

        if (! $settings['metadata_capture']) {
            return null;
        }

        try {
            return $this->persistCapture($case, $request, $clientMeta, $sessionDurationSeconds, $noticeVersion, $settings);
        } catch (\Throwable $e) {
            // A disclosure must never be lost because its metadata row
            // could not be written (R3 shape) — log loudly and move on.
            Log::error('Speak Up metadata capture failed', [
                'case' => $case->case_ref,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function persistCapture(
        SpeakUpCase $case,
        Request $request,
        array $clientMeta,
        ?int $sessionDurationSeconds,
        int $noticeVersion,
        array $settings,
    ): SpeakUpReportMetadata {
        $ip = $request->ip();
        $forwardedChain = $request->header('X-Forwarded-For');
        $ua = substr((string) $request->userAgent(), 0, 500) ?: null;
        $parsed = UserAgentParser::parse($ua);
        $intel = IpIntelligence::resolve($ip);

        $user = $request->user();

        // Device/host name: SSO or internal-agent context only. Browsers
        // do not expose a hostname, and the platform does not guess.
        [$hostname, $hostnameSource] = $this->hostnameFor($request, $user);

        $timezone = $this->str($clientMeta, 'timezone', 80);
        $screen = $this->str($clientMeta, 'screen_resolution', 30);
        $locale = $this->str($clientMeta, 'locale', 120);

        $sources = [
            'network' => $ip ? 'server' : 'unavailable',
            'ip_intelligence' => $intel['resolved'] ? 'server' : 'unresolved',
            'reverse_dns' => $intel['hostname'] !== null ? 'server' : 'unresolved',
            'user_agent' => $ua ? 'server' : 'unavailable',
            'client_device' => $clientMeta === [] ? 'unavailable' : 'client',
            'geo_tz_match' => $this->timezoneCountryMatch($timezone, $intel['geo_country_code']),
        ];

        return SpeakUpReportMetadata::create([
            'tenant_id' => $case->tenant_id,
            'report_id' => $case->id,
            'ip_address' => $ip,
            'ip_forwarded_chain' => $forwardedChain ? substr($forwardedChain, 0, 1000) : null,
            'asn' => $intel['asn'],
            'isp' => $intel['isp'],
            'geo_country' => $intel['geo_country'],
            'geo_region' => $intel['geo_region'],
            'geo_city' => $intel['geo_city'],
            'user_agent_raw' => $ua,
            'browser' => $parsed['browser'],
            'browser_version' => $parsed['browser_version'],
            'os' => $parsed['os'],
            'os_version' => $parsed['os_version'],
            'device_type' => $parsed['device_type'],
            'device_model' => $parsed['device_model'],
            'hostname' => $hostname,
            'hostname_source' => $hostnameSource,
            'screen_resolution' => $screen,
            'timezone' => $timezone,
            'locale' => $locale,
            'fingerprint_hash' => $this->fingerprint($ua, $clientMeta),
            'session_duration_seconds' => $sessionDurationSeconds,
            'referrer' => substr((string) $request->header('Referer'), 0, 500) ?: null,
            'is_authenticated' => $user !== null,
            'reporter_user_id' => $user?->id !== null ? (string) $user->id : null,
            'capture_sources' => $sources,
            'notice_version' => $noticeVersion,
            'notice_acknowledged_at' => now(),
            'captured_at' => now(),
            // Provisional: recomputed from closure date when the case
            // closes (retention runs from case closure, CR §6).
            'purge_after' => now()->addMonths($settings['retention_months']),
        ]);
    }

    /** @return array{0: ?string, 1: string} */
    private function hostnameFor(Request $request, ?User $user): array
    {
        $agentHeader = $request->header('X-Atheris-Device-Name');

        if ($user && $agentHeader) {
            return [substr($agentHeader, 0, 255), 'agent_header'];
        }

        if ($user && is_string($hostname = $request->session()->get('sso_device_name'))) {
            return [substr($hostname, 0, 255), 'sso_session'];
        }

        return [null, 'unavailable'];
    }

    /**
     * The correlation key: SHA-256 of a normalised, ordered attribute
     * string. Stable across networks (no IP in the mix), cleartext by
     * design so repeat-submission signals work without decryption.
     */
    public function fingerprint(?string $ua, array $clientMeta): ?string
    {
        $attributes = [
            'ua' => (string) $ua,
            'platform' => (string) ($clientMeta['platform'] ?? ''),
            'screen' => (string) ($clientMeta['screen_resolution'] ?? ''),
            'depth' => (string) ($clientMeta['color_depth'] ?? ''),
            'tz' => (string) ($clientMeta['timezone'] ?? ''),
            'offset' => (string) ($clientMeta['timezone_offset'] ?? ''),
            'locale' => (string) ($clientMeta['locale'] ?? ''),
            'cores' => (string) ($clientMeta['hardware_concurrency'] ?? ''),
            'memory' => (string) ($clientMeta['device_memory'] ?? ''),
            'touch' => (string) ($clientMeta['touch_support'] ?? ''),
        ];

        if (implode('', $attributes) === '') {
            return null;
        }

        ksort($attributes);

        $normalised = collect($attributes)
            ->map(fn ($value, $key) => $key.'='.mb_strtolower(trim($value)))
            ->implode('|');

        return hash('sha256', $normalised);
    }

    private function str(array $meta, string $key, int $max): ?string
    {
        $value = $meta[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? substr(trim((string) $value), 0, $max)
            : null;
    }

    /** IANA timezone country vs geo-resolved country: match / mismatch / unknown. */
    private function timezoneCountryMatch(?string $timezone, ?string $countryCode): string
    {
        if (! $timezone || ! $countryCode) {
            return 'unknown';
        }

        try {
            $tzCountry = (new \DateTimeZone($timezone))->getLocation()['country_code'] ?? null;
        } catch (\Throwable) {
            return 'unknown';
        }

        if (! $tzCountry || $tzCountry === '??') {
            return 'unknown';
        }

        return strcasecmp($tzCountry, $countryCode) === 0 ? 'match' : 'mismatch';
    }

    // ── Tier 1 signals ───────────────────────────────────────────────────

    /**
     * The "Reporter Signals" card: correlation and anomaly indicators
     * computed without touching a single Tier 2 value. Prior-report
     * figures are aggregate counts only — never case references the
     * viewer may not hold.
     *
     * @return array<string, mixed>|null
     */
    public function signals(SpeakUpCase $case): ?array
    {
        $metadata = SpeakUpReportMetadata::where('report_id', $case->id)->first();

        if (! $metadata) {
            return null;
        }

        $prior = null;

        if ($metadata->fingerprint_hash) {
            $priorCases = SpeakUpCase::withoutGlobalScopes()
                ->join('speak_up_report_metadata as m', 'm.report_id', '=', 'cases.id')
                ->where('m.tenant_id', $case->tenant_id)
                ->where('m.fingerprint_hash', $metadata->fingerprint_hash)
                ->where('cases.id', '!=', $case->id)
                ->get(['cases.status', 'm.captured_at as metadata_captured_at'])
                ->map(fn ($row) => (object) [
                    'status' => $row->status,
                    // Joined column, so no model cast applies — parse here.
                    'captured_at' => Carbon::parse($row->metadata_captured_at),
                ]);

            $outcomes = $priorCases->countBy(fn ($row) => match ($row->status) {
                'Substantiated' => 'substantiated',
                'Unsubstantiated' => 'unsubstantiated',
                'Closed' => 'closed',
                default => 'open',
            });

            $prior = [
                'total' => $priorCases->count(),
                'outcomes' => $outcomes->all(),
                'last_24h' => $priorCases->filter(fn ($row) => $row->captured_at >= now()->subDay())->count(),
                'last_7d' => $priorCases->filter(fn ($row) => $row->captured_at >= now()->subDays(7))->count(),
                'last_30d' => $priorCases->filter(fn ($row) => $row->captured_at >= now()->subDays(30))->count(),
                'previously_unsubstantiated' => ($outcomes['unsubstantiated'] ?? 0) > 0,
            ];
        }

        $sources = $metadata->capture_sources ?? [];

        return [
            'prior_reports' => $prior,
            'anomalies' => [
                'fast_submission' => $metadata->session_duration_seconds !== null
                    && $metadata->session_duration_seconds < (int) config('speakup.fast_submission_seconds', 20),
                // The ISP name alone carries the signal — the hostname is
                // Tier 2 and is never decrypted for a Tier 1 card.
                'datacentre_or_vpn' => IpIntelligence::looksLikeDatacentre($metadata->isp, null),
                'geo_timezone_mismatch' => ($sources['geo_tz_match'] ?? 'unknown') === 'mismatch',
            ],
        ];
    }

    // ── Break-glass reveal (Tier 2) ──────────────────────────────────────

    public function requestReveal(SpeakUpCase $case, User $user, string $reasonCode, string $justification): SpeakUpRevealRequest
    {
        $settings = $this->settings($case->tenant_id);

        if (! array_key_exists($reasonCode, $this->reasonCodes($settings))) {
            throw ValidationException::withMessages([
                'reason_code' => 'Select one of the configured reason codes.',
            ]);
        }

        $existing = SpeakUpRevealRequest::where('report_id', $case->id)
            ->where('requested_by', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'reason_code' => 'You already have a pending reveal request on this report.',
            ]);
        }

        $request = SpeakUpRevealRequest::create([
            'tenant_id' => $case->tenant_id,
            'report_id' => $case->id,
            'requested_by' => $user->id,
            'reason_code' => $reasonCode,
            'justification' => $justification,
            'status' => 'pending',
        ]);

        $this->log($case, 'requested', $request, requestedBy: $user->id);

        return $request;
    }

    public function decideReveal(SpeakUpRevealRequest $request, User $approver, bool $approve, ?string $note = null): SpeakUpRevealRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'This request has already been decided.']);
        }

        // Nobody self-approves — the second person is the control.
        if ($request->requested_by === $approver->id) {
            throw ValidationException::withMessages([
                'decision' => 'A reveal request cannot be approved by the person who made it.',
            ]);
        }

        $request->update([
            'status' => $approve ? 'approved' : 'denied',
            'decided_by' => $approver->id,
            'decision_note' => $note,
            'decided_at' => now(),
            'expires_at' => $approve ? now()->addHours((int) config('speakup.reveal_validity_hours', 72)) : null,
        ]);

        // The approver may hold no case permission at all, so the case is
        // fetched past the allowlist scope purely to stamp the log row.
        $case = SpeakUpCase::withoutGlobalScopes()->findOrFail($request->report_id);

        $this->log($case, $approve ? 'approved' : 'denied', $request,
            requestedBy: $request->requested_by, approvedBy: $approver->id);

        return $request;
    }

    /**
     * Open Tier 2 for a requester holding a usable (approved, unexpired)
     * reveal. Every call — every view, not just the first — writes the
     * immutable access log.
     *
     * @return array<string, mixed>|null
     */
    public function reveal(SpeakUpCase $case, User $user): ?array
    {
        $grant = $this->usableReveal($case, $user);

        if (! $grant) {
            return null;
        }

        $metadata = SpeakUpReportMetadata::where('report_id', $case->id)->first();

        if (! $metadata) {
            return null;
        }

        $this->log($case, 'revealed', $grant,
            requestedBy: $user->id,
            approvedBy: $grant->decided_by,
            fields: SpeakUpReportMetadata::tier2FieldNames());

        $tier2 = $metadata->tier2();

        // Linked staff identity resolves to a name here and nowhere else.
        $tier2['reporter'] = $tier2['reporter_user_id']
            ? User::withoutGlobalScopes()->find($tier2['reporter_user_id'], ['id', 'name', 'email'])
            : null;

        return $tier2;
    }

    public function usableReveal(SpeakUpCase $case, User $user): ?SpeakUpRevealRequest
    {
        return SpeakUpRevealRequest::where('report_id', $case->id)
            ->where('requested_by', $user->id)
            ->where('status', 'approved')
            ->get()
            ->first(fn (SpeakUpRevealRequest $r) => $r->isUsable());
    }

    /** @return array<string, string> */
    public function reasonCodes(?array $settings = null): array
    {
        $codes = ($settings ?? $this->settings(auth()->user()?->tenant_id))['reason_codes'];

        // Stored either as code => label or as a bare list of labels.
        return collect($codes)
            ->mapWithKeys(fn ($label, $key) => is_int($key)
                ? [str($label)->slug('_')->value() => $label]
                : [$key => $label])
            ->all();
    }

    // ── Legal hold ───────────────────────────────────────────────────────

    public function setLegalHold(SpeakUpCase $case, User $user, string $reason): void
    {
        $case->update([
            'legal_hold' => true,
            'legal_hold_reason' => $reason,
            'legal_hold_by' => $user->id,
            'legal_hold_at' => now(),
        ]);

        $case->auditAction('legal-hold-set', null, ['by' => $user->id, 'reason' => $reason]);
    }

    public function liftLegalHold(SpeakUpCase $case, User $user): void
    {
        $case->update([
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_by' => null,
            'legal_hold_at' => null,
        ]);

        $case->auditAction('legal-hold-lifted', null, ['by' => $user->id]);
    }

    // ── Retention ────────────────────────────────────────────────────────

    /** Case closure restarts the clock: retention runs from closure (CR §6). */
    public function restampPurgeDate(SpeakUpCase $case): void
    {
        $metadata = SpeakUpReportMetadata::where('report_id', $case->id)->first();

        if (! $metadata || ! $case->closed_at) {
            return;
        }

        $metadata->update([
            'purge_after' => $case->closed_at->copy()->addMonths(
                $this->settings($case->tenant_id)['retention_months'],
            ),
        ]);
    }

    /**
     * Hard-delete expired metadata rows. The report and its case history
     * stay; a case under legal hold is skipped; every deletion is logged.
     */
    public function purgeExpired(): int
    {
        $expired = SpeakUpReportMetadata::where('purge_after', '<=', now())->get();
        $purged = 0;

        foreach ($expired as $metadata) {
            $case = SpeakUpCase::withoutGlobalScopes()->find($metadata->report_id);

            // Retention runs from closure — an open case keeps its metadata,
            // and a held case keeps it regardless.
            if (! $case || $case->closed_at === null || $case->legal_hold) {
                continue;
            }

            $metadata->delete();
            $purged++;

            $this->log($case, 'purged', null, fields: ['all']);
            $case->auditAction('metadata-purged', null, ['purge_after' => $metadata->purge_after?->toIso8601String()]);
        }

        return $purged;
    }

    // ── The immutable access log ─────────────────────────────────────────

    private function log(
        SpeakUpCase $case,
        string $action,
        ?SpeakUpRevealRequest $request,
        ?int $requestedBy = null,
        ?int $approvedBy = null,
        ?array $fields = null,
    ): void {
        SpeakUpMetadataAccessLog::create([
            'tenant_id' => $case->tenant_id,
            'report_id' => $case->id,
            'reveal_request_id' => $request?->id,
            'action' => $action,
            'requested_by' => $requestedBy,
            'approved_by' => $approvedBy,
            'reason_code' => $request?->reason_code,
            'justification' => $request?->justification,
            'fields_revealed' => $fields,
            'occurred_at' => now(),
        ]);
    }
}
