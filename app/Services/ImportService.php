<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlCategory;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk Excel import (9.7) — every bank arrives with a spreadsheet.
 * Template with data-validated dropdowns → upload → DRY-RUN row-level
 * error report (writes nothing) → transactional import that rolls back
 * completely on any failure.
 */
class ImportService
{
    public const RESOURCES = ['controls', 'risks', 'obligations', 'org-units', 'users'];

    /** column key => [label, required] per resource. */
    public function columns(string $resource): array
    {
        return match ($resource) {
            'controls' => [
                'title' => ['Title', true],
                'description' => ['Description', false],
                'type' => ['Type', true],
                'nature' => ['Nature', true],
                'frequency' => ['Frequency', true],
                'category' => ['Category', false],
                'unit_code' => ['Entity code', false],
                'owner_email' => ['Owner email', false],
                'is_key_control' => ['Key control (Yes/No)', false],
                'function_grouping' => ['Function grouping', false],
                'control_level' => ['Control level', false],
            ],
            'risks' => [
                'title' => ['Title', true],
                'description' => ['Description', false],
                'category' => ['Category', false],
                'inherent_likelihood' => ['Likelihood (1-5)', true],
                'inherent_impact' => ['Impact (1-5)', true],
                'owner_email' => ['Risk owner email', false],
            ],
            'obligations' => [
                'title' => ['Title', true],
                'description' => ['Description', false],
                'regulator_code' => ['Regulator code', true],
                'obligation_type' => ['Obligation type', true],
                'frequency' => ['Frequency', true],
                'legal_reference' => ['Legal reference', false],
                'penalty_description' => ['Penalty description', false],
            ],
            'org-units' => [
                'code' => ['Code', true],
                'name' => ['Name', true],
                'type' => ['Type', true],
                'parent_code' => ['Parent code', false],
            ],
            'users' => [
                'name' => ['Full name', true],
                'email' => ['Email', true],
                'role' => ['Role', true],
                'department' => ['Department', false],
            ],
            default => throw ValidationException::withMessages(['resource' => "Unknown import resource {$resource}."]),
        };
    }

    /** Dropdown options per column, baked into the template as data validation. */
    private function dropdowns(string $resource): array
    {
        return match ($resource) {
            'controls' => [
                'type' => Control::TYPES,
                'nature' => Control::NATURES,
                'frequency' => Control::FREQUENCIES,
                'is_key_control' => ['Yes', 'No'],
                'function_grouping' => Control::FUNCTION_GROUPINGS,
                'control_level' => Control::CONTROL_LEVELS,
                'category' => ControlCategory::query()->orderBy('name')->pluck('name')->all(),
                'unit_code' => OrganisationUnit::query()->orderBy('code')->pluck('code')->all(),
            ],
            'risks' => [
                'inherent_likelihood' => ['1', '2', '3', '4', '5'],
                'inherent_impact' => ['1', '2', '3', '4', '5'],
            ],
            'obligations' => [
                'regulator_code' => Regulator::query()->orderBy('code')->pluck('code')->all(),
                'obligation_type' => [
                    'filing', 'disclosure', 'notification', 'approval', 'registration',
                    'payment', 'assessment', 'training', 'retention', 'operational',
                ],
                'frequency' => [
                    'one_off', 'daily', 'weekly', 'monthly', 'quarterly',
                    'semi_annual', 'annual', 'biennial', 'triennial', 'event_driven',
                ],
            ],
            'org-units' => [
                'type' => ['Head Office', 'Branch', 'Department'],
            ],
            'users' => [
                'role' => Role::query()->orderBy('name')->pluck('name')->all(),
            ],
            default => [],
        };
    }

    // ── Template (with data-validated dropdowns) ─────────────────────────

    public function template(string $resource): StreamedResponse
    {
        $columns = $this->columns($resource);
        $dropdowns = $this->dropdowns($resource);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::headline($resource));

        $headings = array_map(
            fn ($key) => $columns[$key][0].($columns[$key][1] ? ' *' : ''),
            array_keys($columns),
        );
        $sheet->fromArray($headings, null, 'A1');

        $headerRange = 'A1:'.$sheet->getCell([count($headings), 1])->getCoordinate();
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A365D');

        $columnIndex = 1;
        foreach (array_keys($columns) as $key) {
            if (isset($dropdowns[$key]) && $dropdowns[$key] !== []) {
                $formula = '"'.implode(',', array_slice($dropdowns[$key], 0, 60)).'"';

                // Excel list-validation formulas cap at 255 characters.
                if (strlen($formula) <= 255) {
                    for ($row = 2; $row <= 500; $row++) {
                        $validation = $sheet->getCell([$columnIndex, $row])->getDataValidation();
                        $validation->setType(DataValidation::TYPE_LIST)
                            ->setErrorStyle(DataValidation::STYLE_STOP)
                            ->setAllowBlank(true)
                            ->setShowDropDown(true)
                            ->setFormula1($formula);
                    }
                }
            }

            $sheet->getColumnDimensionByColumn($columnIndex)->setWidth(24);
            $columnIndex++;
        }

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"atheris-import-{$resource}.xlsx\"",
        ]);
    }

    // ── Dry-run ──────────────────────────────────────────────────────────

    /**
     * Validate the whole file without writing a single row.
     *
     * @return array{rows: int, valid: int, errors: array<int, array<int, string>>, preview: array}
     */
    public function dryRun(string $resource, UploadedFile $file, User $user): array
    {
        $rows = $this->parse($resource, $file);
        $errors = [];
        $preview = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow($resource, $row, $index, $rows);

            if ($rowErrors !== []) {
                $errors[$index + 2] = $rowErrors; // +2 → spreadsheet row number
            }

            if (count($preview) < 10) {
                $preview[] = $row;
            }
        }

        return [
            'rows' => count($rows),
            'valid' => count($rows) - count($errors),
            'errors' => $errors,
            'preview' => $preview,
        ];
    }

    // ── Import ───────────────────────────────────────────────────────────

    /**
     * All-or-nothing: any invalid row aborts the transaction and nothing
     * is written. A summary audit event is recorded on the tenant.
     */
    public function import(string $resource, UploadedFile $file, User $user): array
    {
        $rows = $this->parse($resource, $file);

        return DB::transaction(function () use ($resource, $rows, $user) {
            $created = 0;

            foreach ($rows as $index => $row) {
                $rowErrors = $this->validateRow($resource, $row, $index, $rows);

                if ($rowErrors !== []) {
                    throw ValidationException::withMessages([
                        'row_'.($index + 2) => $rowErrors,
                    ]);
                }

                $this->writeRow($resource, $row, $user);
                $created++;
            }

            $user->tenant?->auditAction('bulk_imported', null, [
                'resource' => $resource,
                'rows' => $created,
                'by' => $user->id,
            ]);

            return ['created' => $created];
        });
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function parse(string $resource, UploadedFile $file): array
    {
        $keys = array_keys($this->columns($resource));

        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $rows = [];

        foreach ($sheet->toArray(null, true, true, false) as $index => $values) {
            if ($index === 0) {
                continue; // header
            }

            $row = [];
            foreach ($keys as $position => $key) {
                $value = $values[$position] ?? null;
                $row[$key] = is_string($value) ? trim($value) : $value;
            }

            if (array_filter($row, fn ($v) => $v !== null && $v !== '') === []) {
                continue; // fully blank line
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function validateRow(string $resource, array $row, int $index, array $allRows): array
    {
        $errors = [];

        foreach ($this->columns($resource) as $key => [$label, $required]) {
            if ($required && ($row[$key] === null || $row[$key] === '')) {
                $errors[] = "{$label} is required.";
            }
        }

        $inList = function (string $key, array $options, string $label) use ($row, &$errors) {
            if ($row[$key] !== null && $row[$key] !== '' && ! in_array($row[$key], $options, false)) {
                $errors[] = "{$label} '{$row[$key]}' is not a recognised value.";
            }
        };

        switch ($resource) {
            case 'controls':
                $inList('type', Control::TYPES, 'Type');
                $inList('nature', Control::NATURES, 'Nature');
                $inList('frequency', Control::FREQUENCIES, 'Frequency');
                $inList('function_grouping', Control::FUNCTION_GROUPINGS, 'Function grouping');
                $inList('control_level', Control::CONTROL_LEVELS, 'Control level');

                if (! empty($row['unit_code']) && ! OrganisationUnit::where('code', $row['unit_code'])->exists()) {
                    $errors[] = "Entity code '{$row['unit_code']}' does not exist.";
                }
                if (! empty($row['owner_email']) && ! User::where('email', $row['owner_email'])->exists()) {
                    $errors[] = "Owner email '{$row['owner_email']}' does not match a user.";
                }
                break;

            case 'risks':
                foreach (['inherent_likelihood' => 'Likelihood', 'inherent_impact' => 'Impact'] as $key => $label) {
                    $value = $row[$key];
                    if ($value !== null && $value !== '' && (! is_numeric($value) || (int) $value < 1 || (int) $value > 5)) {
                        $errors[] = "{$label} must be a whole number between 1 and 5.";
                    }
                }
                if (! empty($row['owner_email']) && ! User::where('email', $row['owner_email'])->exists()) {
                    $errors[] = "Risk owner email '{$row['owner_email']}' does not match a user.";
                }
                break;

            case 'obligations':
                if (! empty($row['regulator_code']) && ! Regulator::withoutGlobalScopes()->where('code', $row['regulator_code'])->exists()) {
                    $errors[] = "Regulator code '{$row['regulator_code']}' does not exist.";
                }
                $inList('obligation_type', $this->dropdowns('obligations')['obligation_type'], 'Obligation type');
                $inList('frequency', $this->dropdowns('obligations')['frequency'], 'Frequency');
                break;

            case 'org-units':
                $inList('type', ['Head Office', 'Branch', 'Department'], 'Type');

                $duplicate = OrganisationUnit::where('code', $row['code'] ?? '')->exists()
                    || collect($allRows)->take($index)->contains(fn ($r) => $r['code'] === ($row['code'] ?? null));
                if (! empty($row['code']) && $duplicate) {
                    $errors[] = "Code '{$row['code']}' already exists.";
                }
                if (! empty($row['parent_code'])
                    && ! OrganisationUnit::where('code', $row['parent_code'])->exists()
                    && ! collect($allRows)->take($index)->contains(fn ($r) => $r['code'] === $row['parent_code'])) {
                    $errors[] = "Parent code '{$row['parent_code']}' does not exist.";
                }
                break;

            case 'users':
                if (! empty($row['email'])) {
                    if (! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Email '{$row['email']}' is not valid.";
                    } elseif (User::withoutGlobalScopes()->where('email', $row['email'])->exists()
                        || collect($allRows)->take($index)->contains(fn ($r) => $r['email'] === $row['email'])) {
                        $errors[] = "Email '{$row['email']}' already exists.";
                    }
                }
                if (! empty($row['role']) && ! Role::where('name', $row['role'])->exists()) {
                    $errors[] = "Role '{$row['role']}' does not exist.";
                }
                break;
        }

        return $errors;
    }

    private function writeRow(string $resource, array $row, User $user): void
    {
        match ($resource) {
            'controls' => Control::create([
                'tenant_id' => $user->tenant_id,
                'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
                'title' => $row['title'],
                'description' => $row['description'] ?: null,
                'type' => $row['type'],
                'nature' => $row['nature'],
                'frequency' => $row['frequency'],
                'category_id' => $row['category']
                    ? ControlCategory::where('name', $row['category'])->value('id')
                    : null,
                'unit_id' => $row['unit_code']
                    ? OrganisationUnit::where('code', $row['unit_code'])->value('id')
                    : null,
                'owner_id' => $row['owner_email']
                    ? User::where('email', $row['owner_email'])->value('id')
                    : null,
                'is_key_control' => strcasecmp((string) $row['is_key_control'], 'Yes') === 0,
                'function_grouping' => $row['function_grouping'] ?: null,
                'control_level' => $row['control_level'] ?: null,
                'status' => 'Draft',
                'current_version' => 1,
                'created_by' => $user->id,
            ]),
            'risks' => Risk::create([
                'tenant_id' => $user->tenant_id,
                'code' => Risk::nextReference('RSK', false, 'code'),
                'title' => $row['title'],
                'description' => $row['description'] ?: null,
                'category' => $row['category'] ?: null,
                'inherent_likelihood' => (int) $row['inherent_likelihood'],
                'inherent_impact' => (int) $row['inherent_impact'],
                'inherent_rating' => (int) $row['inherent_likelihood'] * (int) $row['inherent_impact'],
                'risk_owner_id' => $row['owner_email']
                    ? User::where('email', $row['owner_email'])->value('id')
                    : null,
                'source' => 'Import',
                'status' => 'Active',
            ]),
            // R10: imported regulatory facts are UNVERIFIED until a human
            // confirms them against the regulator's primary document. The
            // 'unspecified' due rule resolves to no deadline, so no instance
            // is generated until someone completes and verifies it.
            'obligations' => RegulatoryObligation::create([
                'tenant_id' => $user->tenant_id,
                'obligation_ref' => 'OBL-IMP-'.strtoupper(Str::random(6)),
                'regulator_id' => Regulator::withoutGlobalScopes()->where('code', $row['regulator_code'])->value('id'),
                'title' => $row['title'],
                'description' => $row['description'] ?: null,
                'obligation_type' => $row['obligation_type'],
                'frequency' => $row['frequency'],
                'due_rule' => ['type' => 'unspecified'],
                'legal_reference' => $row['legal_reference'] ?: null,
                'penalty_description' => $row['penalty_description'] ?: null,
                'verification_status' => 'unverified',
                'is_active' => true,
            ]),
            'org-units' => OrganisationUnit::create([
                'tenant_id' => $user->tenant_id,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'parent_id' => $row['parent_code']
                    ? OrganisationUnit::where('code', $row['parent_code'])->value('id')
                    : null,
            ]),
            'users' => tap(User::create([
                'tenant_id' => $user->tenant_id,
                'name' => $row['name'],
                'email' => $row['email'],
                'department' => $row['department'] ?: null,
                'password' => Hash::make(Str::random(40)),
                'is_active' => true,
            ]), fn (User $created) => $created->assignRole($row['role'])),
            default => throw ValidationException::withMessages(['resource' => 'Unknown resource.']),
        };
    }
}
