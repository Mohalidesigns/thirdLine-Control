<?php

namespace App\Services;

use App\Models\Regulator;
use App\Models\RegulatoryChange;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RegulatoryChangeNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The circular feed: what the regulator published, what it means for us,
 * and what we did about it.
 *
 * New → Under Review → Impact Assessed → Actioned, with maker-checker on
 * Actioned — the person who wrote the impact assessment cannot also sign off
 * that it has been dealt with (R2). Phase 14 adds AI parsing of circular
 * PDFs, which will land as drafts in exactly this workflow (R4).
 */
class RegulatoryChangeService
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function create(array $data, User $user): RegulatoryChange
    {
        $change = RegulatoryChange::create([
            ...$data,
            'source' => $data['source'] ?? 'manual',
            'status' => 'New',
        ]);

        $change->auditAction('logged', null, ['reference' => $change->reference]);

        return $change;
    }

    public function startReview(RegulatoryChange $change, User $user): RegulatoryChange
    {
        $this->transition($change, 'Under Review');
        $change->auditAction('review-started', null, ['by' => $user->id]);

        return $change;
    }

    /**
     * Record the impact: the narrative plus the obligations and controls the
     * publication touches. The assessor is stamped so the checker can be
     * required to be someone else.
     */
    public function recordAssessment(RegulatoryChange $change, User $user, array $data): RegulatoryChange
    {
        if (trim((string) ($data['impact_assessment'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'impact_assessment' => 'An impact assessment is required before a change can be marked Impact Assessed.',
            ]);
        }

        $this->transition($change, 'Impact Assessed', [
            'impact_assessment' => $data['impact_assessment'],
            'affected_obligation_ids' => $data['affected_obligation_ids'] ?? [],
            'affected_control_ids' => $data['affected_control_ids'] ?? [],
            'assessed_by' => $user->id,
            'assessed_at' => now(),
        ]);

        $change->auditAction('impact-assessed', null, [
            'obligations' => count($data['affected_obligation_ids'] ?? []),
            'controls' => count($data['affected_control_ids'] ?? []),
        ]);

        return $change;
    }

    /** Maker-checker with no administrator bypass (R2). */
    public function markActioned(RegulatoryChange $change, User $user, ?string $notes = null): RegulatoryChange
    {
        if ($change->assessed_by === $user->id) {
            throw ValidationException::withMessages([
                'action' => 'A regulatory change cannot be actioned by the user who wrote its impact assessment.',
            ]);
        }

        $this->transition($change, 'Actioned', [
            'actioned_by' => $user->id,
            'actioned_at' => now(),
        ]);

        $change->auditAction('actioned', null, ['notes' => $notes]);

        return $change;
    }

    public function markNotApplicable(RegulatoryChange $change, User $user, string $reason): RegulatoryChange
    {
        $this->transition($change, 'Not Applicable', [
            'impact_assessment' => $reason,
            'assessed_by' => $user->id,
            'assessed_at' => now(),
        ]);

        $change->auditAction('marked-not-applicable', null, ['reason' => $reason]);

        return $change;
    }

    public function transition(RegulatoryChange $change, string $to, array $extra = []): void
    {
        $allowed = RegulatoryChange::TRANSITIONS[$change->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A regulatory change cannot move from {$change->status} to {$to}.",
            ]);
        }

        $change->update(['status' => $to, ...$extra]);
    }

    /**
     * Poll every regulator that publishes a feed. Items land as New for each
     * tenant, deduplicated on the feed's own GUID. Ingestion never throws:
     * a regulator whose site is down must not stop the rest of the sweep.
     */
    public function ingestFromFeeds(?int $tenantId = null): int
    {
        $regulators = Regulator::query()->active()->whereNotNull('feed_url')->get();

        if ($regulators->isEmpty()) {
            return 0;
        }

        $tenants = Tenant::query()->when($tenantId, fn ($q, $t) => $q->where('id', $t))->get(['id']);
        $ingested = 0;

        foreach ($regulators as $regulator) {
            foreach ($this->fetchFeedItems($regulator) as $item) {
                foreach ($tenants as $tenant) {
                    $ingested += $this->recordFeedItem($regulator, $tenant->id, $item) ? 1 : 0;
                }
            }
        }

        return $ingested;
    }

    /**
     * @return array<int, array{title: string, guid: string, link: ?string, published_at: ?string, summary: ?string}>
     */
    private function fetchFeedItems(Regulator $regulator): array
    {
        try {
            $response = Http::timeout(15)->retry(2, 500)->get($regulator->feed_url);

            if (! $response->successful()) {
                Log::warning('Regulatory feed fetch failed', [
                    'regulator' => $regulator->code,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $xml = @simplexml_load_string($response->body());

            if (! $xml) {
                return [];
            }

            $items = [];

            foreach ($xml->channel->item ?? [] as $item) {
                $guid = trim((string) ($item->guid ?? $item->link ?? $item->title));

                if ($guid === '') {
                    continue;
                }

                $items[] = [
                    'title' => trim((string) $item->title) ?: 'Untitled publication',
                    'guid' => substr($guid, 0, 500),
                    'link' => trim((string) $item->link) ?: null,
                    'published_at' => trim((string) $item->pubDate) ?: null,
                    'summary' => trim(strip_tags((string) $item->description)) ?: null,
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('Regulatory feed ingestion error', [
                'regulator' => $regulator->code,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function recordFeedItem(Regulator $regulator, int $tenantId, array $item): bool
    {
        $exists = RegulatoryChange::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('regulator_id', $regulator->id)
            ->where('source_guid', $item['guid'])
            ->exists();

        if ($exists) {
            return false;
        }

        $change = RegulatoryChange::create([
            'tenant_id' => $tenantId,
            'regulator_id' => $regulator->id,
            'title' => substr($item['title'], 0, 500),
            'document_url' => $item['link'],
            'summary' => $item['summary'],
            'published_at' => $item['published_at'] ? date('Y-m-d H:i:s', strtotime($item['published_at'])) : null,
            'status' => 'New',
            'source' => 'rss',
            'source_guid' => $item['guid'],
        ]);

        $this->notifyComplianceTeam($change, $tenantId);

        return true;
    }

    private function notifyComplianceTeam(RegulatoryChange $change, int $tenantId): void
    {
        $recipients = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Control Function Head', 'Control Officer']))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->dispatcher->send($recipients, 'regulatory-change.published', new RegulatoryChangeNotification($change));
    }
}
