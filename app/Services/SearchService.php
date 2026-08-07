<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Finding;
use App\Models\Risk;
use App\Models\SpotCheck;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Global search (Phase 7.7). Every result set is tenant-scoped (global
 * scopes) AND permission-scoped: a user never sees a title they could
 * not open. MySQL uses the FULLTEXT indexes; other drivers fall back to
 * LIKE so the behaviour is identical in tests.
 */
class SearchService
{
    private const RESULTS_PER_TYPE = 5;

    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $groups = [];

        foreach ($this->searchables($user) as $key => $definition) {
            if (! $user->can($definition['permission'])) {
                continue;
            }

            $query = $definition['query'];
            $this->applyTermFilter($query, $definition['fields'], $term);

            $total = (clone $query)->toBase()->getCountForPagination();

            if ($total === 0) {
                continue;
            }

            $groups[] = [
                'type' => $key,
                'label' => $definition['label'],
                'total' => $total,
                'results' => $query->limit(self::RESULTS_PER_TYPE)->get()
                    ->map($definition['map'])->values(),
            ];
        }

        return $groups;
    }

    private function applyTermFilter(Builder $query, array $fields, string $term): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Boolean mode with a trailing wildcard so prefixes match.
            $boolean = collect(explode(' ', $term))
                ->filter()->map(fn ($word) => '+'.addcslashes($word, '+-<>()~*"@').'*')
                ->implode(' ');

            $query->whereFullText($fields, $boolean, ['mode' => 'boolean']);

            return;
        }

        $query->where(function (Builder $inner) use ($fields, $term) {
            foreach ($fields as $field) {
                $inner->orWhere($field, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Per-model searchable definitions. Later phases append policies,
     * incidents, obligations here.
     */
    private function searchables(User $user): array
    {
        $leadership = $user->hasAnyRole([
            'System Administrator', 'Control Function Head', 'Control Officer',
            'Executive Viewer', 'Line Manager',
        ]);

        return [
            'controls' => [
                'label' => 'Controls',
                'permission' => 'view controls',
                'fields' => ['control_ref', 'title', 'description'],
                'query' => Control::query()->select(['id', 'control_ref', 'title', 'status']),
                'map' => fn (Control $control) => [
                    'id' => $control->id,
                    'reference' => $control->control_ref,
                    'title' => $control->title,
                    'meta' => $control->status,
                    'url' => route('controls.show', $control->id, false),
                ],
            ],
            'risks' => [
                'label' => 'Risks',
                'permission' => 'view risks',
                'fields' => ['code', 'title', 'description'],
                'query' => Risk::query()->select(['id', 'code', 'title', 'status']),
                'map' => fn (Risk $risk) => [
                    'id' => $risk->id,
                    'reference' => $risk->code,
                    'title' => $risk->title,
                    'meta' => $risk->status,
                    'url' => route('risks.show', $risk->id, false),
                ],
            ],
            'exceptions' => [
                'label' => 'Exceptions',
                'permission' => 'view exceptions',
                'fields' => ['reference', 'title', 'description'],
                // Mirrors ExceptionPolicy::view — owners see only their own.
                'query' => ControlException::query()
                    ->select(['id', 'reference', 'title', 'severity', 'status'])
                    ->when(! $leadership, fn ($q) => $q->where(fn ($inner) => $inner
                        ->where('owner_id', $user->id)
                        ->orWhere('responsible_party_id', $user->id))),
                'map' => fn (ControlException $exception) => [
                    'id' => $exception->id,
                    'reference' => $exception->reference,
                    'title' => $exception->title,
                    'meta' => "{$exception->severity} · {$exception->status}",
                    'url' => route('exceptions.show', $exception->id, false),
                ],
            ],
            'test-instances' => [
                'label' => 'Test instances',
                'permission' => 'view tests',
                'fields' => ['reference', 'period_label'],
                'query' => TestInstance::query()
                    ->select(['id', 'reference', 'period_label', 'status', 'control_id'])
                    ->with('control:id,title'),
                'map' => fn (TestInstance $instance) => [
                    'id' => $instance->id,
                    'reference' => $instance->reference,
                    'title' => $instance->control?->title ?? $instance->period_label,
                    'meta' => "{$instance->period_label} · {$instance->status}",
                    'url' => route('test-instances.show', $instance->id, false),
                ],
            ],
            'spot-checks' => [
                'label' => 'Spot checks',
                'permission' => 'view spot-checks',
                'fields' => ['reference', 'title', 'scope_description'],
                'query' => SpotCheck::query()->select(['id', 'reference', 'title', 'status']),
                'map' => fn (SpotCheck $spotCheck) => [
                    'id' => $spotCheck->id,
                    'reference' => $spotCheck->reference,
                    'title' => $spotCheck->title,
                    'meta' => $spotCheck->status,
                    'url' => route('spot-checks.show', $spotCheck->id, false),
                ],
            ],
            'findings' => [
                'label' => 'Findings',
                'permission' => 'view spot-checks',
                'fields' => ['observation', 'recommendation'],
                // Findings carry no tenant_id — scope through the parent check.
                'query' => Finding::query()
                    ->select(['id', 'spot_check_id', 'observation', 'severity'])
                    ->whereHas('spotCheck')
                    ->with('spotCheck:id,reference'),
                'map' => fn (Finding $finding) => [
                    'id' => $finding->id,
                    'reference' => $finding->spotCheck?->reference,
                    'title' => str($finding->observation)->limit(80)->toString(),
                    'meta' => $finding->severity,
                    'url' => route('spot-checks.show', $finding->spot_check_id, false),
                ],
            ],
        ];
    }
}
