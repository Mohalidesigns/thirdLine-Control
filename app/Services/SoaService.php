<?php

namespace App\Services;

use App\Models\ControlRequirementMap;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\SoaEntry;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Statement of Applicability (16.4). Every leaf requirement of a
 * certifiable framework gets a row: the recorded applicability decision,
 * and the live control mappings behind it — the document is derived,
 * never typed, so it cannot drift from the register it describes.
 */
class SoaService
{
    public function decide(Framework $framework, FrameworkRequirement $requirement, array $data, User $user): SoaEntry
    {
        $entry = SoaEntry::updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'framework_id' => $framework->id,
                'requirement_id' => $requirement->id,
            ],
            [
                ...$data,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ],
        );

        $entry->auditAction('soa-decided', null, [
            'requirement' => $requirement->ref_code,
            'is_applicable' => $entry->is_applicable,
            'implementation_status' => $entry->implementation_status,
        ]);

        return $entry;
    }

    /**
     * The full statement: one row per leaf requirement, with the decision
     * (default: applicable, not yet implemented — the honest default for
     * an undeclared control) and the approved control mappings.
     */
    public function statement(Framework $framework): Collection
    {
        $requirements = FrameworkRequirement::query()
            ->where('framework_id', $framework->id)
            ->whereNotExists(fn ($q) => $q->from('framework_requirements as children')
                ->whereColumn('children.parent_id', 'framework_requirements.id'))
            // Pack import writes requirements in document order, so id is
            // the tree's reading order; sort_order only ranks siblings.
            ->orderBy('id')
            ->get(['id', 'ref_code', 'title', 'requirement_type', 'parent_id', 'sort_order']);

        $entries = SoaEntry::query()
            ->where('framework_id', $framework->id)
            ->get()
            ->keyBy('requirement_id');

        $mappings = ControlRequirementMap::query()
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->whereNotNull('control_requirement_map.approved_at')
            ->join('controls', 'controls.id', '=', 'control_requirement_map.control_id')
            ->get([
                'control_requirement_map.requirement_id',
                'control_requirement_map.coverage',
                'controls.control_ref',
                'controls.title as control_title',
            ])
            ->groupBy('requirement_id');

        return $requirements->map(function (FrameworkRequirement $requirement) use ($entries, $mappings) {
            $entry = $entries->get($requirement->id);
            $mapped = $mappings->get($requirement->id, collect());

            return [
                'requirement_id' => $requirement->id,
                'ref_code' => $requirement->ref_code,
                'title' => $requirement->title,
                'is_applicable' => $entry?->is_applicable ?? true,
                'justification' => $entry?->justification,
                'implementation_status' => $entry?->implementation_status
                    ?? ($mapped->isNotEmpty() ? 'partially_implemented' : 'not_implemented'),
                'decided_by' => $entry?->decider?->name,
                'decided_at' => $entry?->decided_at?->toDateString(),
                'controls' => $mapped->map(fn ($m) => [
                    'control_ref' => $m->control_ref,
                    'title' => $m->control_title,
                    'coverage' => $m->coverage,
                ])->all(),
            ];
        });
    }

    public function renderPdf(Framework $framework): \Barryvdh\DomPDF\PDF
    {
        $statement = $this->statement($framework);

        return Pdf::loadView('reports.soa', [
            'framework' => $framework,
            'statement' => $statement,
            'summary' => [
                'total' => $statement->count(),
                'applicable' => $statement->where('is_applicable', true)->count(),
                'excluded' => $statement->where('is_applicable', false)->count(),
                'implemented' => $statement->where('implementation_status', 'implemented')->count(),
            ],
            'template' => [
                'name' => 'Statement of Applicability',
                'header' => ['subtitle' => 'Statement of Applicability — '.$framework->name],
                'footer' => ['text' => 'Confidential — derived from the live control register'],
            ],
        ])->setPaper('a4', 'landscape');
    }
}
