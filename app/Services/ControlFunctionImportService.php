<?php

namespace App\Services;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlFrequency;
use App\Models\ControlFunctionImport;
use App\Models\ControlFunctionImportRow;
use App\Models\ControlUnit;
use App\Models\TestScript;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * CR-03 §D: the client's checklist workbook becomes data, not code.
 *
 * The bank owns this document and will revise it, so the importer — not
 * a seeder — is the primary path, and the seeder calls straight through
 * to it (§D.3) so the two can never drift.
 *
 * Three properties the change request asks for, and where each lives:
 *
 *  - FORWARD FILL. `Units` and `Function` are written once and left
 *    blank on continuation rows. A naive row-by-row read produces 1,517
 *    orphans, so every reader carries the last non-blank value down.
 *  - LOUD FAILURE. An unrecognised frequency marks its row `unresolved`
 *    and blocks the commit. It never falls through to Monthly: a Daily
 *    control quietly rescheduled as monthly is the failure mode this
 *    whole table exists to prevent (§B.2 Gap 1).
 *  - VERSIONING, NOT MUTATION. If a control's Active script differs from
 *    what the workbook now says, we raise version_no + 1 as Draft and
 *    leave v1 executing. Officers never execute a checklist mid-change.
 */
class ControlFunctionImportService
{
    /**
     * Sheet configuration. `scope` decides what a function attaches to:
     * `entity` gives each Units value its own desk; `branch` holds the
     * function once as a template and attaches it to every branch (§D.2).
     */
    public const SHEETS = [
        'Head Office Control' => ['abbr' => 'HO', 'unit_code' => 'HOC', 'scope' => 'entity'],
        'Branch Control' => ['abbr' => 'BR', 'unit_code' => 'BRC', 'scope' => 'branch'],
    ];

    /**
     * Zero-based column positions. Passed as a map rather than assumed,
     * so a renamed header does not break the run (§D.1 step 1).
     */
    public const COLUMNS = ['unit' => 1, 'function' => 2, 'checklist' => 3, 'frequency' => 4];

    public function __construct(private FrequencyResolver $frequencies) {}

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * Read the workbook into canonical, forward-filled rows. The same
     * shape the content pack stores, so seeder and upload take one path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsFromSpreadsheet(string $path, array $sheets = self::SHEETS, array $columns = self::COLUMNS): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $rows = [];

        foreach ($sheets as $sheetName => $config) {
            $sheet = $book->getSheetByName($sheetName);

            if (! $sheet) {
                throw ValidationException::withMessages([
                    'file' => sprintf('This workbook has no "%s" sheet. Expected: %s.', $sheetName, implode(', ', array_keys($sheets))),
                ]);
            }

            $lastUnit = null;
            $lastFunction = null;

            foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
                $rowNo = $index + 1;

                // Row 1 is the spacer the client's layout opens with and
                // row 2 the header; everything below is checklist.
                if ($rowNo <= 2) {
                    continue;
                }

                $unit = $this->clean($cells[$columns['unit']] ?? null);
                $function = $this->clean($cells[$columns['function']] ?? null);
                $checklist = $this->clean($cells[$columns['checklist']] ?? null);
                $frequency = $this->clean($cells[$columns['frequency']] ?? null);

                // Forward fill — the merged-cell hierarchy (§A.1).
                $lastUnit = $unit !== '' ? $unit : $lastUnit;
                $lastFunction = $function !== '' ? $function : $lastFunction;

                // Blank spacer rows separate functions and carry nothing.
                if ($checklist === '') {
                    continue;
                }

                if ($lastUnit === null || $lastFunction === null) {
                    throw ValidationException::withMessages([
                        'file' => sprintf('Row %d of "%s" has a checklist line before any unit or function — the sheet layout is not what the importer expects.', $rowNo, $sheetName),
                    ]);
                }

                $rows[] = [
                    'sheet' => $sheetName,
                    'row_no' => $rowNo,
                    'source_ref' => $config['abbr'].'!'.Coordinate::stringFromColumnIndex($columns['frequency'] + 1).$rowNo,
                    'unit' => $lastUnit,
                    'function' => $lastFunction,
                    'checklist' => $checklist,
                    'frequency' => $frequency,
                ];
            }
        }

        return $rows;
    }

    /** The committed JSON extract of the workbook (§D.3). */
    public function loadPack(string $version = '1.0.0'): array
    {
        $path = database_path("content-packs/atheris-control-functions/{$version}.json");

        if (! is_file($path)) {
            throw new RuntimeException("Control function content pack {$version} not found at {$path}.");
        }

        $pack = json_decode((string) file_get_contents($path), true);

        if (! is_array($pack) || ! isset($pack['rows'])) {
            throw new RuntimeException("Control function content pack {$version} is malformed.");
        }

        return $pack;
    }

    // ── The run ──────────────────────────────────────────────────────

    /**
     * Stage a run and produce the diff, writing nothing to the control
     * library. This is the screen an operator reviews before committing.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function dryRun(array $rows, array $meta, int $tenantId, ?User $actor = null): ControlFunctionImport
    {
        return DB::transaction(function () use ($rows, $meta, $tenantId, $actor) {
            $import = ControlFunctionImport::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'reference' => $this->nextReference($tenantId),
                'source_name' => $meta['source_name'] ?? 'Checklist workbook',
                'source_hash' => $meta['source_hash'] ?? null,
                'source_version' => $meta['source_version'] ?? null,
                'status' => 'Dry Run',
                'rows_total' => count($rows),
                'created_by' => $actor?->id,
            ]);

            $plan = $this->plan($rows, $tenantId);

            $this->stageRows($import, $rows, $plan);

            $import->forceFill([
                'rows_unresolved' => count($plan['unresolved']),
                'controls_added' => $plan['totals']['controls_added'],
                'controls_changed' => $plan['totals']['controls_changed'],
                'items_added' => $plan['totals']['items_added'],
                'items_changed' => $plan['totals']['items_changed'],
                'items_removed' => $plan['totals']['items_removed'],
                'scripts_versioned' => $plan['totals']['scripts_versioned'],
                'diff_report' => $plan['diff'],
            ])->save();

            return $import->fresh('rows');
        });
    }

    /**
     * Apply a staged run. Refuses anything with an unresolved row — the
     * operator must map the frequency first.
     */
    public function commit(ControlFunctionImport $import, ?User $actor = null): ControlFunctionImport
    {
        if ($import->status !== 'Dry Run') {
            throw ValidationException::withMessages([
                'import' => 'Only a dry run can be committed — start a new import.',
            ]);
        }

        if ($import->rows_unresolved > 0) {
            throw ValidationException::withMessages([
                'import' => sprintf(
                    '%d row(s) carry a frequency this system does not recognise. Add an alias for each one, then re-run the import.',
                    $import->rows_unresolved,
                ),
            ]);
        }

        $rows = $import->rows()->orderBy('row_no')->get()
            ->map(fn (ControlFunctionImportRow $row) => [
                'sheet' => $row->sheet,
                'row_no' => $row->row_no,
                'source_ref' => $row->source_ref,
                'unit' => $row->unit_raw,
                'function' => $row->function_raw,
                'checklist' => $row->checklist_raw,
                'frequency' => $row->frequency_raw,
            ])
            ->all();

        try {
            return DB::transaction(function () use ($import, $rows, $actor) {
                $plan = $this->plan($rows, $import->tenant_id);
                $applied = $this->apply($plan, $import->tenant_id, $actor);

                $import->forceFill([
                    'status' => 'Committed',
                    'committed_at' => now(),
                    'controls_added' => $applied['controls_added'],
                    'controls_changed' => $applied['controls_changed'],
                    'items_added' => $applied['items_added'],
                    'items_changed' => $applied['items_changed'],
                    'items_removed' => $applied['items_removed'],
                    'scripts_versioned' => $applied['scripts_versioned'],
                    'diff_report' => $plan['diff'],
                ])->save();

                $import->auditAction('control-functions-imported', null, [
                    'reference' => $import->reference,
                    'controls_added' => $applied['controls_added'],
                    'controls_changed' => $applied['controls_changed'],
                    'scripts_versioned' => $applied['scripts_versioned'],
                ]);

                return $import->fresh();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $import->forceFill(['status' => 'Failed', 'error' => $e->getMessage()])->save();

            throw $e;
        }
    }

    /** Dry run then commit, for the seeder and the console path. */
    public function import(array $rows, array $meta, int $tenantId, ?User $actor = null): ControlFunctionImport
    {
        return $this->commit($this->dryRun($rows, $meta, $tenantId, $actor), $actor);
    }

    // ── Planning ─────────────────────────────────────────────────────

    /**
     * Group the flat rows into units → functions → lines, resolve every
     * frequency, and diff the result against what is already in the
     * library. Writes nothing.
     */
    public function plan(array $rows, int $tenantId): array
    {
        $units = [];
        $unresolved = [];

        foreach ($rows as $row) {
            $unitKey = mb_strtoupper($this->clean($row['unit']));
            // Case-insensitive on purpose: the workbook writes NOSTRO's
            // unit name in two casings and would otherwise split one desk
            // into two (§A.2).
            $title = $this->stripNumbering($this->clean($row['function']));
            $functionKey = mb_strtoupper($title);

            $frequencyRaw = $this->clean($row['frequency'] ?? '');
            $frequency = null;

            if ($frequencyRaw !== '') {
                $frequency = $this->frequencies->resolve($frequencyRaw, $tenantId);

                if (! $frequency) {
                    $unresolved[$row['row_no'].'|'.$row['sheet']] = $frequencyRaw;
                }
            }

            $units[$unitKey] ??= [
                'name' => $this->clean($row['unit']),
                'sheet' => $row['sheet'],
                'functions' => [],
            ];

            $units[$unitKey]['functions'][$functionKey] ??= [
                'title' => $title,
                'source_ref' => $row['source_ref'],
                'items' => [],
            ];

            $units[$unitKey]['functions'][$functionKey]['items'][] = [
                'row_no' => $row['row_no'],
                'sheet' => $row['sheet'],
                'question' => $this->stripNumbering($this->clean($row['checklist'])),
                'frequency_raw' => $frequencyRaw !== '' ? $frequencyRaw : null,
                'frequency' => $frequency,
                'source_ref' => $row['source_ref'],
            ];
        }

        // A function's own rhythm is the one most of its lines carry;
        // lines that differ become overrides (§C.2). Blank lines inherit,
        // which is what the client means by leaving the cell empty.
        foreach ($units as $unitKey => $unit) {
            foreach ($unit['functions'] as $functionKey => $function) {
                $dominant = $this->dominantFrequency($function['items']);

                $units[$unitKey]['functions'][$functionKey]['frequency'] = $dominant;
                $units[$unitKey]['functions'][$functionKey]['frequency_raw'] = $this->dominantRaw($function['items']);

                foreach ($function['items'] as $i => $item) {
                    // NULL override = inherit. Only a line whose own
                    // frequency differs from the function's carries one.
                    $override = $item['frequency'] && $dominant && $item['frequency']->id !== $dominant->id
                        ? $item['frequency']
                        : null;

                    $units[$unitKey]['functions'][$functionKey]['items'][$i]['override'] = $override;
                }
            }
        }

        return [
            'units' => $units,
            'unresolved' => $unresolved,
            ...$this->diff($units, $unresolved, $tenantId),
        ];
    }

    /** Diff the planned shape against the library, per unit (§D.1 step 9). */
    private function diff(array $units, array $unresolved, int $tenantId): array
    {
        $diff = [];
        $totals = [
            'controls_added' => 0, 'controls_changed' => 0,
            'items_added' => 0, 'items_changed' => 0, 'items_removed' => 0,
            'scripts_versioned' => 0,
        ];

        foreach ($units as $unit) {
            $entry = [
                'unit' => $unit['name'],
                'sheet' => $unit['sheet'],
                'functions' => count($unit['functions']),
                'lines' => array_sum(array_map(fn ($f) => count($f['items']), $unit['functions'])),
                'added' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'unresolved' => 0,
            ];

            foreach ($unit['functions'] as $function) {
                $existing = $this->findControl($tenantId, $unit, $function['title']);

                if (! $existing) {
                    $entry['added']++;
                    $totals['controls_added']++;
                    $totals['items_added'] += count($function['items']);

                    continue;
                }

                $script = $existing->testScripts()->where('status', 'Active')->orderByDesc('version_no')->first();
                $current = $script ? $script->checkItems->pluck('question')->all() : [];
                $incoming = array_map(fn ($i) => $i['question'], $function['items']);

                if ($current === $incoming) {
                    $entry['unchanged']++;

                    continue;
                }

                $entry['changed']++;
                $totals['controls_changed']++;
                $totals['scripts_versioned']++;
                $totals['items_added'] += count(array_diff($incoming, $current));
                $totals['items_removed'] += count(array_diff($current, $incoming));
                $totals['items_changed'] += max(0, min(count($current), count($incoming)) - count(array_intersect($current, $incoming)));
            }

            $entry['unresolved'] = count(array_filter(
                $unresolved,
                fn ($key) => str_ends_with($key, '|'.$unit['sheet']),
                ARRAY_FILTER_USE_KEY,
            ));

            $diff[] = $entry;
        }

        return ['diff' => $diff, 'totals' => $totals];
    }

    // ── Applying ─────────────────────────────────────────────────────

    private function apply(array $plan, int $tenantId, ?User $actor): array
    {
        $applied = [
            'controls_added' => 0, 'controls_changed' => 0,
            'items_added' => 0, 'items_changed' => 0, 'items_removed' => 0,
            'scripts_versioned' => 0,
        ];

        foreach ($plan['units'] as $unit) {
            $config = self::SHEETS[$unit['sheet']] ?? null;

            if (! $config) {
                throw ValidationException::withMessages([
                    'import' => sprintf('Sheet "%s" has no import configuration.', $unit['sheet']),
                ]);
            }

            $controlUnit = $this->resolveControlUnit($tenantId, $config['unit_code']);
            // §D.2: a head office desk gets its own control entity; a
            // branch function is held ONCE against Branch Control and
            // executed against every branch, never copied per branch.
            $entity = $config['scope'] === 'entity'
                ? $this->resolveDeskEntity($tenantId, $controlUnit, $unit['name'])
                : null;

            $abbr = $this->abbreviate($unit['name']);
            $sequence = 0;

            foreach ($unit['functions'] as $function) {
                $sequence++;
                $control = $this->upsertControl($tenantId, $controlUnit, $entity, $abbr, $sequence, $function, $actor, $applied);
                $this->upsertChecklist($control, $function, $actor, $applied);

                if ($config['scope'] === 'branch') {
                    $this->attachToBranches($control, $controlUnit);
                } elseif ($entity) {
                    $entity->controls()->syncWithoutDetaching([
                        $control->id => ['tenant_id' => $tenantId, 'is_key' => false],
                    ]);
                }
            }
        }

        return $applied;
    }

    /**
     * §D.1 step 6. Natural key is (tenant, control unit, control entity,
     * title) — never the title alone: `REVIEW OF PROOF OF GL ACCOUNTS`
     * appears under two desks and `MONTHLY STOCK COUNT` under three.
     */
    private function upsertControl(
        int $tenantId, ControlUnit $controlUnit, ?ControlEntity $entity,
        string $abbr, int $sequence, array $function, ?User $actor, array &$applied,
    ): Control {
        $frequency = $function['frequency'];

        $existing = Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('control_unit_id', $controlUnit->id)
            ->where('control_entity_id', $entity?->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower($function['title'])])
            ->first();

        $attributes = [
            'title' => $function['title'],
            'frequency' => $frequency?->legacy_frequency ?? 'Monthly',
            'frequency_id' => $frequency?->id,
            'frequency_raw' => $function['frequency_raw'],
            'source_ref' => $function['source_ref'],
            'is_control_function' => true,
            'control_unit_id' => $controlUnit->id,
            'control_entity_id' => $entity?->id,
            'owner_id' => $entity?->default_officer_id ?? $entity?->owner_id ?? $controlUnit->head_user_id,
        ];

        if ($existing) {
            $existing->forceFill($attributes)->save();
            $applied['controls_changed'] += $existing->wasChanged() ? 1 : 0;

            return $existing;
        }

        $applied['controls_added']++;

        return Control::withoutGlobalScopes()->create([
            ...$attributes,
            'tenant_id' => $tenantId,
            'control_ref' => $this->nextControlRef($tenantId, $controlUnit->code, $abbr, $sequence),
            // A checklist is a second-line review performed after the
            // fact: detective by definition, and manual by construction.
            'type' => 'Detective',
            'nature' => 'Manual',
            'status' => 'Active',
            'description' => sprintf('Departmental control function checklist — %s.', $function['title']),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * §D.1 steps 7 and 8. A changed checklist becomes version_no + 1 as a
     * DRAFT; the Active script keeps running until somebody approves the
     * new one. Item ids survive where the text is unchanged, so historical
     * check_results keep pointing at a real item.
     */
    private function upsertChecklist(Control $control, array $function, ?User $actor, array &$applied): void
    {
        $incoming = $function['items'];
        $active = $control->testScripts()->where('status', 'Active')->orderByDesc('version_no')->first();

        if ($active) {
            $current = $active->checkItems()->orderBy('sequence')->get();

            if ($current->pluck('question')->all() === array_map(fn ($i) => $i['question'], $incoming)) {
                // Text identical — refresh only the frequency metadata,
                // which is not what officers are executing against.
                $this->syncItems($active, $incoming, $applied, countChanges: false);

                return;
            }

            $draft = $control->testScripts()->where('status', 'Draft')->orderByDesc('version_no')->first();

            $script = $draft ?: TestScript::withoutGlobalScopes()->create([
                'tenant_id' => $control->tenant_id,
                'control_id' => $control->id,
                'version_no' => ((int) $control->testScripts()->max('version_no')) + 1,
                'title' => $function['title'],
                'status' => 'Draft',
                'created_by' => $actor?->id,
            ]);

            $applied['scripts_versioned'] += $draft ? 0 : 1;
            $this->syncItems($script, $incoming, $applied);

            return;
        }

        $script = $control->testScripts()->orderByDesc('version_no')->first()
            ?? TestScript::withoutGlobalScopes()->create([
                'tenant_id' => $control->tenant_id,
                'control_id' => $control->id,
                'version_no' => 1,
                'title' => $function['title'],
                // A first import is the checklist the bank already works
                // to — it goes live, it is not a proposal.
                'status' => 'Active',
                'created_by' => $actor?->id,
            ]);

        $this->syncItems($script, $incoming, $applied);
    }

    private function syncItems(TestScript $script, array $incoming, array &$applied, bool $countChanges = true): void
    {
        $existing = $script->checkItems()->orderBy('sequence')->get()->keyBy('sequence');
        $seen = [];

        foreach ($incoming as $index => $item) {
            $sequence = $index + 1;
            $seen[] = $sequence;
            $current = $existing->get($sequence);

            $attributes = [
                'question' => $item['question'],
                'frequency_id' => $item['override']?->id,
                'frequency_raw' => $item['frequency_raw'],
                'source_ref' => $item['source_ref'],
                // §C.7 mitigation 2: an observation line is guidance, not
                // a test — it must not force a write on every execution.
                'is_mandatory' => $item['override']?->isContinuous() !== true,
            ];

            if ($current) {
                $current->fill($attributes);

                if ($current->isDirty() && $countChanges) {
                    $applied['items_changed']++;
                }

                $current->save();

                continue;
            }

            CheckItem::create([...$attributes, 'test_script_id' => $script->id, 'sequence' => $sequence]);

            if ($countChanges) {
                $applied['items_added']++;
            }
        }

        $stale = $script->checkItems()->whereNotIn('sequence', $seen)->get();

        foreach ($stale as $item) {
            // Soft delete: a historical check_result still points here.
            $item->delete();

            if ($countChanges) {
                $applied['items_removed']++;
            }
        }
    }

    /**
     * §D.2: the branch function set attaches to every branch entity. It
     * is one control with many entities, not one control per branch —
     * 73 functions across 250 branches would otherwise be 18,250 rows to
     * keep in step every time a checklist line changes.
     */
    public function attachToBranches(Control $control, ControlUnit $branchUnit): int
    {
        $branches = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $control->tenant_id)
            ->where('control_unit_id', $branchUnit->id)
            ->where('entity_kind', 'branch')
            ->where('is_active', true)
            ->pluck('id');

        $attached = 0;

        foreach ($branches as $branchId) {
            $exists = DB::table('control_entity_control')
                ->where('control_entity_id', $branchId)
                ->where('control_id', $control->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('control_entity_control')->insert([
                'tenant_id' => $control->tenant_id,
                'control_entity_id' => $branchId,
                'control_id' => $control->id,
                'is_key' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $attached++;
        }

        return $attached;
    }

    // ── Resolution helpers ───────────────────────────────────────────

    private function resolveControlUnit(int $tenantId, string $code): ControlUnit
    {
        $unit = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                'import' => sprintf('Control sub-unit "%s" does not exist for this tenant — seed the control structure first.', $code),
            ]);
        }

        return $unit;
    }

    /** §D.1 step 5: a head office desk, created if the workbook implies one. */
    private function resolveDeskEntity(int $tenantId, ControlUnit $controlUnit, string $name): ControlEntity
    {
        $existing = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('control_unit_id', $controlUnit->id)
            ->whereNull('parent_id')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return $existing;
        }

        return ControlEntity::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'control_unit_id' => $controlUnit->id,
            'reference' => app(ControlStructureService::class)->nextEntityReference($tenantId),
            'name' => $name,
            'entity_kind' => 'department',
            'is_import_created' => true,
            'is_active' => true,
        ]);
    }

    private function findControl(int $tenantId, array $unit, string $title): ?Control
    {
        $config = self::SHEETS[$unit['sheet']] ?? null;

        if (! $config) {
            return null;
        }

        $controlUnit = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('code', $config['unit_code'])->first();

        if (! $controlUnit) {
            return null;
        }

        $entityId = null;

        if ($config['scope'] === 'entity') {
            $entityId = ControlEntity::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('control_unit_id', $controlUnit->id)
                ->whereNull('parent_id')
                ->whereRaw('lower(name) = ?', [mb_strtolower($unit['name'])])
                ->value('id');

            if (! $entityId) {
                return null;
            }
        }

        return Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('control_unit_id', $controlUnit->id)
            ->where('control_entity_id', $entityId)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->with(['testScripts.checkItems'])
            ->first();
    }

    // ── Staging ──────────────────────────────────────────────────────

    private function stageRows(ControlFunctionImport $import, array $rows, array $plan): void
    {
        // Index the plan so a staged row can carry the resolution its
        // function landed on, not just its own.
        $functionState = [];

        foreach ($plan['diff'] as $unitDiff) {
            $functionState[$unitDiff['unit']] = $unitDiff;
        }

        $records = [];
        $now = now();

        foreach ($rows as $row) {
            $key = $row['row_no'].'|'.$row['sheet'];
            $raw = $this->clean($row['frequency'] ?? '');
            $frequency = $raw === '' ? null : $this->frequencies->resolve($raw, $import->tenant_id);

            $resolution = 'unchanged';
            $message = null;

            if (isset($plan['unresolved'][$key])) {
                $resolution = 'unresolved';
                $message = sprintf('"%s" is not a frequency this system knows.', $raw);
            } elseif ($raw === '') {
                $message = 'Blank — inherits the function\'s frequency.';
            }

            $records[] = [
                'control_function_import_id' => $import->id,
                'row_no' => $row['row_no'],
                'sheet' => $row['sheet'],
                'source_ref' => $row['source_ref'] ?? null,
                'unit_raw' => Str::limit($row['unit'], 250, ''),
                'function_raw' => $row['function'],
                'checklist_raw' => $row['checklist'],
                'frequency_raw' => $raw !== '' ? $raw : null,
                'frequency_id' => $frequency?->id,
                'resolution' => $resolution,
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('control_function_import_rows')->insert($chunk);
        }
    }

    // ── Text and reference helpers ───────────────────────────────────

    /**
     * §D.1 step 3. The source carries non-breaking spaces as leading
     * indentation, trailing double spaces in unit names ("Trade  Control")
     * and lines up to 613 characters. Normalisation is a build step.
     */
    public function clean(?string $value): string
    {
        $value = str_replace(["\xc2\xa0", "\r\n", "\r", "\n", "\t"], ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** Strip the numbering the client baked into the text: (1), 1., -, :. */
    public function stripNumbering(string $value): string
    {
        return trim(preg_replace('/^(?:[\(\[]\s*\d+\s*[\)\]]|\d+\s*[\.\)]|[-–—•*:])\s*/u', '', $value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function dominantFrequency(array $items): ?ControlFrequency
    {
        $counts = [];
        $rows = [];

        foreach ($items as $item) {
            if (! $item['frequency']) {
                continue;
            }

            $counts[$item['frequency']->id] = ($counts[$item['frequency']->id] ?? 0) + 1;
            $rows[$item['frequency']->id] = $item['frequency'];
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return $rows[array_key_first($counts)];
    }

    private function dominantRaw(array $items): ?string
    {
        $counts = [];

        foreach ($items as $item) {
            if ($item['frequency_raw'] === null) {
                continue;
            }

            $counts[$item['frequency_raw']] = ($counts[$item['frequency_raw']] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return array_key_first($counts);
    }

    /** HOC-HCM-001, BRC-BIC-001 — a per-unit sequence (§D.1 step 6). */
    private function nextControlRef(int $tenantId, string $unitCode, string $abbr, int $sequence): string
    {
        $prefix = $unitCode.'-'.$abbr.'-';

        do {
            $candidate = $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $taken = Control::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('control_ref', $candidate)
                ->exists();
            $sequence++;
        } while ($taken);

        return $candidate;
    }

    /** A short, stable, per-unit tag for the control reference. */
    public function abbreviate(string $name): string
    {
        $pack = $this->packAbbreviations();
        $key = mb_strtoupper($this->clean($name));

        if (isset($pack[$key])) {
            return $pack[$key];
        }

        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // "CONTROL", "OFFICE" and friends appear in nearly every unit
        // name and tell a reader nothing.
        $meaningful = array_values(array_filter($words, fn ($w) => ! in_array(mb_strtoupper($w), ['CONTROL', 'HEAD', 'OFFICE', 'INTERNAL', 'ACCOUNTS', 'THE', 'AND', 'OF'], true)));
        $words = $meaningful !== [] ? $meaningful : $words;

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 3));
        }

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 3)))) ?: 'GEN';
    }

    private ?array $packAbbreviations = null;

    private function packAbbreviations(): array
    {
        if ($this->packAbbreviations !== null) {
            return $this->packAbbreviations;
        }

        try {
            $pack = $this->loadPack();
        } catch (RuntimeException) {
            return $this->packAbbreviations = [];
        }

        $map = [];

        foreach ($pack['unit_abbreviations'] ?? [] as $name => $abbr) {
            $map[mb_strtoupper($this->clean($name))] = $abbr;
        }

        return $this->packAbbreviations = $map;
    }

    private function nextReference(int $tenantId): string
    {
        $last = ControlFunctionImport::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->value('reference');

        $next = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'CFI-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
