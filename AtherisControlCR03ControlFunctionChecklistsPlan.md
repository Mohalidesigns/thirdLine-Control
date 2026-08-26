# Atheris Control — CR-03 · Departmental Control Function Checklists & Frequency Engine

**Change request:** turn the client's *ATHERIS — Departmental Control Function Checklists* workbook into a live, scheduled, per-unit control-task engine inside Atheris Control, where **Frequency of Activity** is the thing that manufactures work rather than a note in a spreadsheet column.

- **Status:** Plan — no code written yet
- **Prepared:** 25 August 2026
- **Source artefact:** `ATHERIS_ Departmental Control Function Checklists.xlsx` (3 sheets, 1,517 checklist lines)
- **Baseline:** `atheris-control` @ 182 migrations, Laravel 13 / Inertia 3 / React, post CR-01 (Exception Manager) and CR-02 (Internal Control Structure)
- **Decisions taken with the client:** frequency **auto-generates task instances**; completion is tracked **per checklist line**

---

## Part A — What the workbook actually contains

### A.1 Structure

Three sheets, two of which carry the checklist:

| Sheet | Columns | Rows | Units | Functions | Checklist lines |
|---|---|---|---|---|---|
| `Head Office Control` | S/N · Units · **Function** · Checklist · Frequency of Activity | 855 | 6 | 94 | 801 |
| `Branch Control` | S/N · Units · **Desk / Role** · Checklist · Frequency of Activity | 803 | 1 | 73 | 716 |
| `REQUIRED` | S/N · Operations Process | 21 | — | — | 20 process names |

**Total: 167 control functions, 1,517 checklist lines.**

The layout is a classic merged-cell hierarchy: `Units` and `Function` are written once and left blank on the continuation rows. Every checklist line inherits the last non-blank value above it. Blank spacer rows separate functions. Any parser must carry the last-seen value forward — a naive row-by-row read produces 1,517 orphans.

### A.2 The units

`Head Office Control` — six second-line desks:

| # | Unit as written | Functions | Lines |
|---|---|---|---|
| 1 | HEAD OFFICE HCM/VFM CONTROL | 26 | 181 |
| 2 | GSS Control | 15 | 202 |
| 3 | Trade Control | 19 | 186 |
| 4 | LCY Treasury Control | 21 | 128 |
| 5 | FCY Treasury Control | 12 | 88 |
| 6 | NOSTRO Accounts Reconciliation | 1 | 16 |

`Branch Control` — a single unit, **BRANCH INTERNAL CONTROL**, holding 73 functions and 716 lines. This is not one desk's workload: it is the **template** every branch control officer executes, so its true volume is 73 × (number of branches).

### A.3 The frequency vocabulary

The column is free text and is not clean. Raw values across all 1,517 lines:

| Raw value | Lines | Reading |
|---|---|---|
| `Daily` | 860 | Daily |
| `Monthly` | 356 | Monthly |
| `Weekly` | 203 | Weekly |
| *(blank)* | 34 | Inherit the function's frequency |
| `Observation` | 16 | Continuous / walk-around observation, no fixed cycle |
| `On request` | 13 | Event-driven, triggered by a request |
| `As per sales by CBN` | 9 | Event-driven, triggered by a CBN FX sale |
| `bi-annually` | 8 | Twice a year — **needs client confirmation** |
| `twice annually` | 6 | Twice a year |
| `Quaterly` | 5 | Quarterly (misspelling in source) |
| `Yearly` | 5 | Annual |
| `Half yearly` | 1 | Twice a year |
| `Anytime there a new circular` | 1 | Event-driven, triggered by a new CBN circular |

Four distinct spellings resolve to "twice a year" and one to "quarterly". `bi-annually` is genuinely ambiguous in English (twice a year vs every two years); Nigerian banking usage is almost always twice a year, and the sheet uses `twice annually` and `Half yearly` alongside it for the same idea, so we will read it as **Semi-annual** — but it is listed in §G as a confirm-before-go-live item.

### A.4 The awkward cases the model must survive

**Seven functions carry more than one frequency across their own checklist lines.** Frequency is therefore *not* purely a property of the function:

| Unit | Function | Frequencies present |
|---|---|---|
| HEAD OFFICE HCM/VFM CONTROL | MONTHLY STOCK COUNT | Daily ×5, Monthly ×1, blank ×1 |
| Trade Control | REVIEW OF FORM M | Daily ×4, Monthly ×5 |
| LCY Treasury Control | REVIEW OF FUNDS TRANSFER (LCY) | Daily ×4, Monthly ×1 |
| LCY Treasury Control | ANALYSIS OF CASH SHORTAGES / PENALTIES / FAKE NOTES | Daily, Weekly, Monthly, Half yearly — one line each |
| NOSTRO Accounts Reconciliation | NOSTRO | Daily ×11, Monthly ×5 |
| BRANCH INTERNAL CONTROL | Review of accounts with unscanned / duplicated mandates | Daily ×6, Weekly ×6 |
| BRANCH INTERNAL CONTROL | ANNUAL TAX ASSESSMENTS / LEVIES / DEVELOPMENT LEVY | Daily ×12, Yearly ×5 |

**Fourteen functions have blank frequency on at least one line**, and one — `Trade Control · MONTHLY REVIEW OF EXCHANGE CONTROL` — is blank on **all nine** of its lines (its title implies Monthly; flag for confirmation).

**Function names repeat across units.** `REVIEW OF PROOF OF GL ACCOUNTS` appears under two desks, `MONTHLY STOCK COUNT` under three, `GL REVIEW:` under two. Uniqueness must key on **(unit, function)**, never on the function name alone.

**Source text is dirty.** Non-breaking spaces (`\xa0`) as leading indentation, trailing double spaces in unit names (`Trade  Control`), numbering prefixes baked into the text (`(1)`, `(2)`), a stray `:` prefix on one function title, and lines up to **613 characters** long. Normalisation is a build step, not an afterthought.

### A.5 Load implied by the frequency column

Counting each function by its dominant frequency:

| | Daily fns | Weekly fns | Monthly fns | Other |
|---|---|---|---|---|
| Head Office (6 desks) | 52 | 3 | 31 | 8 |
| Branch (per branch) | 36 | 19 | 15 | 3 |

Head Office generates ~52 task instances and ~474 line-level responses **per day**, fixed. Branch scales with the network:

| Branches | Daily instances/day | Daily line responses/day | Line responses/year (all frequencies) |
|---|---|---|---|
| 50 | ~1,800 | ~20,400 | ~5.6 m |
| 100 | ~3,600 | ~40,800 | ~11.2 m |
| 250 | ~9,000 | ~102,000 | ~28 m |

This is the single most consequential number in the change request and it drives §D.4 (partitioning and retention) and §F (phasing).

---

## Part B — Where this lands in the existing solution

### B.1 The good news: the engine already exists

Atheris Control already has the exact five-table shape this workbook describes. Nothing here needs a parallel structure:

```
Control (a control function, carries `frequency`)
  └── TestScript (the checklist, versioned, approvable)
        └── CheckItem (one checklist line, ordered, mandatory flag, severity on fail)

TestInstance (one occurrence of the function for one period)
  └── CheckResult (Pass / Fail / NA + comment per CheckItem)
        └── ControlException (auto-raised on every Fail)
```

`TestingService::generateScheduledInstances()` already runs nightly via `secondline:generate-test-instances` at 01:00 and already reads `controls.frequency` to compute a period. CR-02 already gave us `control_units` (Head Office / Information Systems / Branch) and `control_entities` (departments, IS domains, branches, branch activities) with a `control_entity_control` pivot.

**So the workbook maps cleanly onto the existing domain:**

| Workbook concept | Atheris model |
|---|---|
| Sheet (`Head Office Control` / `Branch Control`) | `ControlUnit` (`HOC`, `BRC` — already seeded) |
| `Units` column (HCM/VFM, GSS, Trade, LCY, FCY, NOSTRO) | `ControlEntity`, `entity_kind = 'department'`, parent unit `HOC` |
| `BRANCH INTERNAL CONTROL` | `ControlUnit` `BRC`; work executes against each branch `ControlEntity` |
| `Function` / `Desk / Role` | `Control` (+ `control_entity_control` link) |
| `Checklist` line | `CheckItem` on the control's Active `TestScript` |
| `Frequency of Activity` | `controls.frequency` + new per-item override |
| An officer's due task | `TestInstance` |
| A ticked line | `CheckResult` |
| A failed line | `ControlException` (already automatic on submit) |

### B.2 The four real gaps

**Gap 1 — Frequency vocabulary is too narrow.**
`controls.frequency` is `enum('Daily','Weekly','Monthly','Quarterly','Semi-annual','Annual','Event-driven')`. It cannot express `Observation` (continuous), cannot distinguish *why* an event-driven control fires (`On request` vs `CBN FX sale` vs `new circular`), and discards the client's own wording. `TestingService::periodFor()` has no branch for anything outside the enum and silently falls through to Monthly — a Daily control mislabelled would quietly become monthly.

**Gap 2 — Frequency lives only at the function level.**
Seven functions need line-level frequency. Today there is nowhere to put it: `check_items` has no frequency column, so `ANALYSIS OF CASH SHORTAGES` would have to be split into four separate controls, breaking the one-to-one with the client's document.

**Gap 3 — `TestInstance` cannot be scoped to a branch.** *(the blocking one)*
`test_instances` has `control_id` but **no `control_entity_id`**, and its uniqueness is `UNIQUE(control_id, period_label)`. One branch-control function shared by 100 branches can therefore produce exactly **one** instance per day for the whole network. Every branch would fight over the same row. This must be fixed before any branch checklist is seeded, and it is the only change that touches code already in production use (`TestingService`, `TestInstancePolicy`, testing reports, `MyWorkService`).

**Gap 4 — No import path from the client's own document.**
`ImportService` handles flat resource sheets, not this merged, hierarchical layout. The bank will revise this workbook; a one-off seeder that cannot be re-run against version 2 is a liability.

---

## Part C — Target design

### C.1 Frequency as a first-class object

Replace the enum-only approach with a **frequency definition table plus a preserved raw label**. Behaviour keys on `cycle`, never on the display string — the same rule CR-02 applied to `control_units.domain`.

`control_frequencies` *(new, tenant-scoped, seeded)*

| Column | Type | Notes |
|---|---|---|
| `code` | string(30) | `daily`, `weekly`, `monthly`, `quarterly`, `semiannual`, `annual`, `on_request`, `observation`, `cbn_fx_sale`, `cbn_circular` |
| `label` | string | Display name |
| `cycle` | enum | `daily` `weekly` `monthly` `quarterly` `semiannual` `annual` `continuous` `event` |
| `generation_mode` | enum | `scheduled` · `continuous` · `event` |
| `grace_days` | unsigned int | Days after period end before overdue (Daily 1, Weekly 2, Monthly 5, Quarterly 10, Semi-annual 15, Annual 20) |
| `trigger_event` | string, nullable | `request_received`, `cbn_fx_sale`, `cbn_circular_published` |
| `is_active`, `sequence` | | |

`frequency_aliases` *(new)* — every raw string the workbook uses, mapped to a `control_frequencies.code`, so a re-import of a revised workbook resolves `Quaterly`, `bi-annually`, `twice annually`, `Half yearly` without anyone editing PHP. Unknown strings fail the import loudly rather than defaulting to Monthly.

The seeded alias map:

| Raw | → code |
|---|---|
| `Daily` | `daily` |
| `Weekly` | `weekly` |
| `Monthly` | `monthly` |
| `Quaterly`, `Quarterly` | `quarterly` |
| `bi-annually`, `twice annually`, `Half yearly`, `Semi-annual` | `semiannual` |
| `Yearly`, `Annual` | `annual` |
| `On request` | `on_request` |
| `Observation` | `observation` |
| `As per sales by CBN` | `cbn_fx_sale` |
| `Anytime there a new circular` | `cbn_circular` |
| *(blank)* | inherit from parent function |

`controls.frequency` (the existing enum) is **kept** and stays the compatibility surface for everything already reading it; a new nullable `controls.frequency_id` FK is the authority when present, and `controls.frequency_raw` preserves the client's exact wording for the audit trail and for round-tripping back to Excel. A model accessor resolves `frequency_id → frequency` so no existing caller breaks.

### C.2 Line-level frequency override

`check_items` gains:

| Column | Notes |
|---|---|
| `frequency_id` | nullable FK — **null means inherit the control's frequency** (covers 1,483 of 1,517 lines) |
| `frequency_raw` | nullable, the source string, blank preserved as null |
| `source_ref` | nullable string — `HO!D412`, the originating cell, for traceability back to the workbook |

Generation reads: for period *P* of frequency *F*, an instance of control *C* includes the check items whose effective frequency is *F*. So `NOSTRO` produces a **daily** instance containing its 11 daily lines and a **monthly** instance containing its 5 monthly lines — one control, one checklist, two rhythms, exactly as the client wrote it.

### C.3 Scoping instances to a unit — the branch fix

`test_instances` gains:

| Column | Notes |
|---|---|
| `control_entity_id` | nullable FK to `control_entities` — which desk or branch this occurrence belongs to |
| `scope_key` | string(40), **not null**, `'e'.control_entity_id` or `'global'` |
| `frequency_id` | nullable FK — which rhythm produced this instance (a control with mixed lines produces one instance per rhythm) |

Uniqueness changes from `UNIQUE(control_id, period_label)` to
`UNIQUE(control_id, scope_key, period_label, frequency_id)`.

`scope_key` exists because MySQL does not collide NULLs inside a unique index — a nullable `control_entity_id` would silently permit duplicate global instances and re-break idempotency the moment the nightly job ran twice. The column is written by the model's `saving` hook, never by hand.

**Migration is additive and backfilled**: `scope_key` defaults to `'global'` for all existing rows, `frequency_id` stays null, the old unique index is dropped and the new one added in the same migration. Existing behaviour is unchanged for every control that is not entity-scoped.

### C.4 Assignment — who gets the task

Ownership resolves in this order, first hit wins:

1. `control_entities.owner_id` — the desk's or branch's control officer (the normal path)
2. `control_units.head_user_id` — the unit head, if the entity has no owner
3. `controls.owner_id` — today's behaviour, retained as the fallback
4. Unassigned + a notification to the unit head, if none of the above resolve

Reviewer resolves to the unit head; where tester and reviewer would be the same person, the instance is submitted unreviewed and escalates to the next level up — the existing SoD rule in `TestingService::review()` already refuses a self-review and must not be weakened.

### C.5 Event-driven and observation frequencies

- `generation_mode = 'scheduled'` — the nightly job creates the instance. Daily / Weekly / Monthly / Quarterly / Semi-annual / Annual.
- `generation_mode = 'event'` — no automatic instance. A user or an integration calls `ControlTaskService::raiseEventInstance($control, $entity, $triggerContext)`, which stamps `is_ad_hoc = true` and records what triggered it. Covers `On request`, `As per sales by CBN`, `Anytime there a new circular`. A small **Trigger** button on the function page plus an API hook for the CBN circular feed already polled by `atheris:poll-regulatory-feeds`.
- `generation_mode = 'continuous'` (`Observation`) — a rolling instance per entity that never closes on a period boundary; the officer records observations against it, and it rolls into a fresh instance monthly for reporting. This keeps `BRANCH AMBIENCE` and `REVIEW OF VAULT / ATM DOORS` out of the overdue queue, which is where a Daily mapping would wrongly put them.

### C.6 Complete schema delta

**New tables (4)**

| Table | Purpose |
|---|---|
| `control_frequencies` | The frequency catalogue with cycle, grace and generation mode |
| `frequency_aliases` | Raw workbook string → frequency code |
| `control_function_imports` | One row per workbook upload: file hash, sheet, counts, diff report, actor |
| `control_function_import_rows` | Per-row staging with resolution status, for dry-run review before commit |

**Altered tables (4)**

| Table | Columns added |
|---|---|
| `controls` | `frequency_id`, `frequency_raw`, `source_ref`, `is_control_function` (bool) |
| `check_items` | `frequency_id`, `frequency_raw`, `source_ref` |
| `test_instances` | `control_entity_id`, `scope_key`, `frequency_id`; unique index replaced |
| `control_entities` | `default_officer_id` (distinct from `owner_id`, which is the relationship officer) |

No table is dropped. No column is removed. Every alter is additive with a backfill.

### C.7 Volume control

At 250 branches the branch checklist alone writes ~28 m `check_results` rows a year. Three mitigations, all in Phase 5:

1. **Partition `check_results` and `test_instances` by year** (MySQL `RANGE` on a `period_year` column) so the working set stays the current year.
2. **`OPTIONAL` check items.** Roughly 15% of the branch lines are guidance rather than a test (`Observe the branch ambience`). Marking those `is_mandatory = false` and offering a "confirm all remaining as reviewed" bulk action cuts the write volume without weakening evidence on the lines that matter.
3. **Retention.** Reuse the existing `retention_policies` machinery: closed daily instances older than 24 months compress to a summary row (counts by result + exception links) and drop their line detail, subject to the regulator's record-keeping minimum. This is a policy decision, not a technical one — §G.

---

## Part D — Import: the workbook becomes data, not code

The bank owns this document and will revise it. The importer, not a seeder, is the primary path.

### D.1 `ControlFunctionImportService`

Steps, all inside one transaction with a dry-run mode:

1. **Read** — `phpoffice/phpspreadsheet` (already a dependency). Accept the sheet name and a column map so a renamed header does not break the run.
2. **Forward-fill** — carry `Units` and `Function` down through blank continuation rows; discard fully blank spacer rows.
3. **Normalise text** — `\xa0` → space, collapse runs of whitespace, trim, strip leading numbering (`(1)`, `1.`, `-`), strip a leading `:`. Keep the raw string in `*_raw` columns; never overwrite the source.
4. **Resolve frequency** — via `frequency_aliases`, case- and whitespace-insensitive. Blank → inherit. **Unknown → row marked `unresolved`, import blocks.**
5. **Resolve unit** → `ControlEntity` for Head Office desks; `ControlUnit` `BRC` for the branch sheet. Create missing entities as `entity_kind = 'department'`, flagged as import-created.
6. **Upsert control** — natural key `(tenant_id, control_unit_id, control_entity_id, title)`. Assign `control_ref` from a per-unit sequence: `HOC-HCM-001`, `BRC-001`. Set `type = 'Detective'`, `nature = 'Manual'`, `status = 'Active'`, `is_control_function = true`.
7. **Version the checklist** — if the control has an Active `TestScript` whose item set differs, create `version_no + 1` as Draft rather than mutating the live script. This is what makes revision-2 of the workbook safe: officers keep executing v1 until someone approves v2.
8. **Upsert check items** — keyed on `(test_script_id, sequence)`; preserve item ids where text is unchanged so historical `check_results` keep pointing at a real item.
9. **Diff report** — added / changed / removed / unresolved per unit, rendered for review before commit, and stored on `control_function_imports` as the audit record.

### D.2 Branch instantiation

Branch functions are held once as a **template** against the `BRC` unit and executed against each branch `ControlEntity`. They are *not* copied per branch — `control_entity_control` already links a control to many entities, and CR-02's `SyncControlStructureBranches` command already provisions a `ControlEntity` for every branch in the organisation tree. Extending that command to attach the branch function set to each newly-provisioned branch means a new branch inherits all 73 functions on the day it opens, with no data duplication and one place to fix a checklist.

### D.3 Seeder

`ControlFunctionChecklistSeeder` calls the same import service against a JSON extract of the workbook committed at `database/content-packs/atheris-control-functions/1.0.0.json`. Same code path as the UI import, so the seeder cannot drift from the importer. Idempotent by the natural keys above.

---

## Part E — Application surface

### E.1 Backend

| Component | Work |
|---|---|
| `ControlTaskService` *(new)* | Generation across entities and frequencies; event triggers; continuous rolling instances; assignment resolution (§C.4) |
| `FrequencyResolver` *(new)* | Alias → code; `periodFor(code, asOf)` returning label, start, end, due date; replaces the `match` in `TestingService` |
| `ControlFunctionImportService` *(new)* | §D.1 |
| `TestingService` | `generateScheduledInstances()` delegates to `ControlTaskService`; `periodFor()` delegates to `FrequencyResolver`. Signature preserved. |
| `MyWorkService` | Surface entity-scoped instances; group by control unit |
| `Console\Commands\GenerateControlTasks` *(new)* | `atheris:generate-control-tasks`, replaces the generation half of `secondline:generate-test-instances`, chunked and `--dry-run`-able |
| `SyncControlStructureBranches` | Attach the branch function set on branch provisioning (§D.2) |
| `ControlFunctionController`, `ControlFunctionImportController` *(new)* | Catalogue, import wizard |
| Policies | `ControlFunctionPolicy`; extend `TestInstancePolicy` so an officer sees only their entity's instances |

**Schedule** (`routes/console.php`) — before the 07:00 escalation sweep, so a task that fell due overnight escalates the same morning, matching the existing convention:

```php
// CR-03 — departmental control function checklists.
Schedule::command('atheris:generate-control-tasks')->dailyAt('00:45')->withoutOverlapping();
Schedule::command('atheris:roll-continuous-tasks')->monthlyOn(1, '00:50');
```

`00:45` deliberately precedes the existing `01:00` test-instance job so both are complete before ageing refresh at 01:15.

### E.2 Frontend (Inertia + React, existing component set only)

| Page | Content |
|---|---|
| `Pages/ControlFunctions/Index.jsx` | Catalogue: unit → function → line count → frequency badge → next due. Filters: unit, frequency, owner, status. |
| `Pages/ControlFunctions/Show.jsx` | One function: checklist lines with per-line frequency chips, version history, entities it applies to, instance history |
| `Pages/ControlFunctions/Import.jsx` | Upload → dry-run diff (added/changed/removed/unresolved, per unit) → commit |
| `Pages/TestInstances/Execute.jsx` *(extend)* | Line-level Pass/Fail/NA with mandatory comment on Fail and NA (existing rule), evidence attach per line, save-as-you-go, offline queue via the existing `offline/` support |
| `Pages/Dashboard.jsx` *(extend)* | "Control tasks due today" tile, per-unit completion rate, overdue-by-unit bar |
| `Pages/ControlStructure/Unit.jsx` *(extend)* | Function count and completion rate per entity |

A **frequency badge** component (`FrequencyBadge.jsx`) styled from the existing token layer: colour by cycle, tooltip showing the client's raw wording. The badge is what makes "Frequency of Activity" visible everywhere the client expects to see it.

### E.3 Permissions & feature flag

New permissions, added to `RolePermissionSeeder` alongside the existing `view tests` / `execute tests` family:

- `view control-functions` — Control Officer, Unit Head, Head of Internal Control, Auditor, Executive
- `manage control-functions` — Head of Internal Control, Admin
- `import control-functions` — Admin only
- `execute control-tasks` — Control Officer, Unit Head
- `review control-tasks` — Unit Head, Head of Internal Control

Feature flag `control-functions`, gating the routes exactly as `control-structure` does, so the module can ship dark and be enabled per tenant.

### E.4 Reporting

Reusing `ReportDefinition` / `ReportSchedule`, which already carry per-schedule cron and timezone:

1. **Daily Control Task Completion** — by unit, by officer; completion %, overdue count, exceptions raised. Auto-mails the Head of Internal Control at 08:00.
2. **Frequency Compliance** — for each function, expected vs actual instances over a window. This is the report that proves a Daily control was actually performed daily, and is what an examiner asks for.
3. **Checklist Exception Register** — every Fail with its line text, officer, entity and the `ControlException` it raised.
4. **Branch Control Scorecard** — completion rate per branch, ranked, drilling to the function.
5. **Round-trip export** — the register back to the client's own workbook layout, so the document they gave us stays the document they recognise.

---

## Part F — Phased delivery

### Phase 1 — Frequency foundation *(≈3 days)*
Migrations for `control_frequencies` and `frequency_aliases`; seeder with the ten codes and the full alias map; `FrequencyResolver` with `periodFor()`; `TestingService::periodFor()` delegated. `controls.frequency_id` / `frequency_raw` added and backfilled from the existing enum.

*Done when:* every existing control resolves to a frequency with an unchanged period label, and the full existing test suite passes untouched.

### Phase 2 — Entity-scoped instances *(≈4 days, the risky one)*
`test_instances.control_entity_id` + `scope_key` + `frequency_id`; unique index swap with backfill to `'global'`; `TestingService` and `TestInstancePolicy` updated; `MyWorkService` scoped.

*Done when:* the nightly job run twice in a row creates zero duplicates; two branches can hold open instances of the same control on the same day; every pre-existing instance still resolves and displays.

*Risk:* this touches live testing data. Ship behind the feature flag, rehearse the migration on a production-shaped dump, and keep the down-migration exercised.

### Phase 3 — Checklist import *(≈5 days)*
`ControlFunctionImportService`, the two staging tables, the dry-run diff, the JSON content pack, `ControlFunctionChecklistSeeder`, and the Head Office desks provisioned as `ControlEntity` rows.

*Done when:* importing the client's workbook produces exactly 167 controls, 167 test scripts, 1,517 check items and 0 unresolved rows; re-importing the same file reports zero changes; importing an edited file produces a Draft v2 script and leaves v1 executing.

### Phase 4 — Task generation & execution *(≈6 days)*
`ControlTaskService`, `atheris:generate-control-tasks`, event triggers, continuous rolling instances, assignment resolution, the three new pages and the extended execution screen.

*Done when:* a Head Office officer logging in on a Monday sees the correct daily, weekly and monthly tasks for their desk; a branch officer sees only their branch's; a Fail raises an exception with the line text on it; the seven mixed-frequency functions each produce separate instances per rhythm.

### Phase 5 — Scale, reporting & rollout *(≈5 days)*
Partitioning, retention policy, the five reports, dashboards, the round-trip export, and a load rehearsal at the client's true branch count.

*Done when:* a generation run at the client's branch count completes inside its window and the task list renders in under a second at the 95th percentile.

**Total ≈ 23 working days**, roughly five weeks with review, sequential because each phase depends on the last. Phases 1 and 3 can overlap partially if two developers are available.

---

## Part G — Decisions the client must confirm before Phase 3

1. **`bi-annually`** (8 lines, Vendor Registration and Security Equipment Review) — twice a year, or every two years? Plan assumes **twice a year**.
2. **`MONTHLY REVIEW OF EXCHANGE CONTROL`** (Trade Control, 9 lines, no frequency on any line) — Monthly, per the title? Plan assumes **Monthly**.
3. **`Observation`** (16 branch lines) — a continuous rolling task the officer records against, or a Daily task that appears in the overdue queue when missed? Plan assumes **continuous**.
4. **Branch count** and the branch roll-out order — drives the Phase 5 sizing and whether partitioning is needed at go-live or later.
5. **Grace days per frequency** — the plan proposes Daily 1 / Weekly 2 / Monthly 5 / Quarterly 10 / Semi-annual 15 / Annual 20. The Monthly figure matches the existing `due_date = period_end + 5 days`.
6. **Retention** — how long line-level daily evidence must survive before summarising. CBN and the bank's own records policy govern; this is a compliance answer, not an engineering one.
7. **The `REQUIRED` sheet** (20 operations processes) — currently unmapped. It reads as a coverage checklist: *which operations processes must have control functions covering them*. Recommended as a **Phase 6** coverage-matrix view mapping each process to the functions that touch it, surfacing gaps. Confirm the intent.
8. **Head Office desk owners** — the six desks need named control officers before generation, or every Head Office task lands unassigned.

---

## Part H — What this delivers

The client's spreadsheet stops being a document someone consults and becomes the thing that runs the control function:

- Every one of the 167 functions has a named owner, a rhythm and a due date.
- Every one of the 1,517 checklist lines is ticked by a person, at a time, with a comment and evidence where it matters.
- **Frequency of Activity is the engine.** A Daily function that was not performed yesterday is visibly overdue this morning, per desk and per branch, without anyone chasing a spreadsheet.
- A failed line becomes an exception in the CR-01 Exception Manager automatically, with its SLA, routing and escalation ladder already built.
- The bank can answer "prove this control was performed at its stated frequency for the last twelve months" from one report.
- The workbook can be revised and re-imported without a developer, and versioned so officers never execute a checklist mid-change.

**No parallel structure is built.** Every piece lands in machinery Atheris Control already has — controls, test scripts, check items, test instances, check results, exceptions, escalations, evidence, reporting. The change request is, at its core, four additive schema changes, one corrected unique index, an importer, and a scheduler that understands the client's own vocabulary.

---

## Appendix A — Function inventory by unit

### Head Office Control

**HEAD OFFICE HCM/VFM CONTROL** — 26 functions, 181 lines
Payroll Review (6, Monthly) · Review of New Hires Files (11, Monthly) · Review of Confirmed Staff Files (6, Monthly) · Review of Exited Staff (7, Weekly) · Review of DC Issues (7, Monthly) · Administration of Entry Level Recruitment Test (7, Monthly) · Review of Booked Staff Loans (6, Monthly) · Review of Staff Redeployment (5, Monthly) · Review of Proof of GL Accounts (8, Monthly) · Executive Mortgage Rebate (9, Monthly) · Security Sweep (5, Quarterly) · Staff Lateness Tracking (20, Weekly) · Statutory Compliance — PAYE/Pension/Tax/Workman (5, Monthly) · Staff Transactions in Business Offices (5, Monthly) · Staff Leave Utilization (3, Monthly) · Pre-Disbursement Reviews (7, Daily) · Disposal of Assets (8, On request) · Review of Monthly Proof of Accounts (8, Monthly) · Vendor Registration Process (4, Bi-annual) · Security Equipment Review (4, Bi-annual) · Market Survey (5, Monthly) · Call Over of Transaction Ticket (6, Daily) · Daily GL Review (9, Daily) · Bidding and Negotiation Process (5, On request) · Monthly Stock Count (7, **mixed**) · Identification of Active Processors on Core Banking (8, Daily)

**GSS Control** — 15 functions, 202 lines
Bankers Acceptance Disbursement Callover (27, Daily) · Loan Disbursement Callover (27, Daily) · Bonds & Guarantees Disbursement Callover (21, Daily) · Overdraft Disbursement Callover (19, Daily) · Monthly Stock Count (12, Monthly) · Callover of NAPS Transactions (18, Daily) · Callover of NEFT Transactions (10, Daily) · Clearing Cheques Review (9, Daily) · Review of Deleted Transactions by Branch Support (8, Daily) · Review of Interest Rate Amendment (9, Daily) · Review of Account Maintenance Charge Concession (9, Daily) · Review of Withdrawal/Transfer Charges on Domiciliary (9, Daily) · Review of Transactions Posted by Branch Support (9, Daily) · Review of Failed Standing Instruction (7, Daily) · Review of Proof of GL Accounts (8, Monthly)

**Trade Control** — 19 functions, 186 lines
Review of GL — Special Reference to FX Account (10, Daily) · Cash Count and Vault Administration (14, Weekly) · Review of Proof of Account (7, Daily) · Call-Over of MoneyGram/Western Union & FCY Cash (10, Daily) · LC Establishment Review (14, Monthly) · Review of Form M (9, **mixed**) · Review of Advance Payment for SMEs (8, Monthly) · Review of Due Obligations (6, Monthly) · Monthly Stock Count (7, Monthly) · Review of Export NXP (12, Monthly) · Review of Invisible Files / Form A (9, Monthly) · Review of Outstanding Shipping Documents (13, Monthly) · Monthly Review of Exchange Control (9, **unspecified**) · Call Over of Invisible Transactions Form A (11, Daily) · Review of Personal Home Remittances (5, Daily) · Call-Over of Trade Operations Transactions (10, Daily) · Bid Review (9, As per CBN sale) · Monthly Review of Repatriation of Export Proceeds (12, Monthly) · Review of PTA and BTAs (11, Monthly)

**LCY Treasury Control** — 21 functions, 128 lines
GL Review (5, Daily) · Review of Rate Upload in FT for Revaluing Bonds & T-Bills (6, Daily) · Fixed Income Trading Deals Review (8, Daily) · Money Market — Interbank OBB Taking and Placement (10, Daily) · Review of BA/CP (8, Daily) · Treasury and Investment Product Review (8, Daily) · Review of Funds Transfer LCY (5, **mixed**) · Monthly Circularisation to All Banks (7, Monthly) · External Auditor Enquiry (4, Monthly) · CBN Reconciliation (10, Daily) · CRR Reconciliation (6, Monthly) · Review of Proofs of TROPS Accounts (7, Daily) · Monitoring of CBN Closing Balance (6, Daily) · FMDQ Daily Surveillance Report (3, Daily) · Rate Reasonability Review (5, Daily) · Analysis of Cash Shortages / Penalties / Fake Notes (4, **mixed**) · S4 Reconciliation — T-Bills and Bond Position (5, Monthly) · Update of CBN Circular (1, Event) · Blotter Review (9, Daily) · Review of Failed OTC Trades (7, Daily) · MT103 Inflow Review (4, Daily)

**FCY Treasury Control** — 12 functions, 88 lines
GL Review (5, Daily) · Fixed Income Trading Deals — Euro Bonds (7, Daily) · Money Market — FCY Taking and Placement (9, Daily) · Review/Call Over of Funds Transfer & Remittances FCY (5, Daily) · USD Position Account Reconciliation (9, Daily) · EUR Position Account Reconciliation (9, Daily) · GBP Position Account Reconciliation (9, Daily) · Call-Over of TROPS FCY Transactions (8, Daily) · Deal Confirmation on SWIFT (6, Daily) · Daily Review of IMTO Funds Remittance to CBN (7, Daily) · Preparation of FCCL (7, Daily) · Review of Proofs of TROPS FCY Accounts (7, Monthly)

**NOSTRO Accounts Reconciliation** — 1 function, 16 lines
NOSTRO (16, **mixed** — 11 Daily, 5 Monthly)

### Branch Control — BRANCH INTERNAL CONTROL

73 functions, 716 lines, executed per branch.

**Daily (36 functions, 408 lines)** — Review of GL Movements · Call Over of Processed Tickets · Review of Modified Mandates / Static Data · Review of Truncated Clearing Cheque · Manager's Cheque / Bank Draft · Review of Expenses · Cash Advance Review · Credit Transaction Review · Overdraft & Temporary Overdraft · Treasury and Investment Product Review · Income Leakage Review · Review of Suspense/Transit/Proxy Accounts · Accounts Opened Without BVN & PND · System Exception Override Message Report · Reactivation of Dormant/Inactive Accounts · BDC Transactions PTA · BDC Transactions BTA · Debit and Credit Cards Issuance · Cross Currency · Security and Environmental Scanning · Cash Deposits Without Deposit Receipts · Online Review of Transactions (HFTI) · Daily ATM Difference Reconciliation · Collections (PAYE, WHT, FIRS) · FX Transactions Form A · FX Transactions Form M · FCY Transfer Domiciliary Outflow · Review of Foreign Currency Outflows · Inter/Intra Bank & Bulk Credit Transfers · Inward Inflows into a SOL · New Accounts Data Captured at Branch · Review of Front Tellers Activities · Checklist for Cash Pick Up Service · Checklist (Cash Management) · Review of Accounts with Unscanned Mandates *(mixed)* · Annual Tax Assessments/Levies *(mixed)*

**Weekly (19 functions, 157 lines)** — Customers Telephones & Email Addresses · BVN Validation · General Ledger Proofing · Call Memos · CCTV · Remittance Account / PayDirect · Shipping Documents · Fixed/Call Deposit / FTD Liquidation · E-Banking Enrolment · Trapped ATM Cards · Customer Complaints / Card Blocking · Network Port / Router / IT Room · Generator Review · Interest Rate on Fixed Term Deposit · Review of CMC-Vault · CMC Cash Supply Procedure · Cash Evacuation Procedure · Premises and Brand Management · CMC Resource/Safety/Cash Movement

**Monthly (15 functions, 129 lines)** — Expatriate Resident Permit & ID · Pool Cars · Interest Rate on Loans & Advances · Non-Performing Credits · Other Critical Equipment · Vault Administration · Account Closure/Transfer · Cheque Requisition · Fixed Assets · POS Deployment to Merchant · NIBSS e-Reference · Web Pay (PayDirect, Bank Collect, Pay4Me) · Hybrid e-Payments (Quickteller, PayArena, PayAttitude) · Social Network Banking · Kiosk (Bill Payment, Supermarket, Lotteries, Ticketing)

**Observation (2 functions, 16 lines)** — Branch Ambience · Review of Vault / ATM Doors
**Twice annually (1 function, 6 lines)** — Review of Stores

---

## Appendix B — The `REQUIRED` sheet

Twenty operations processes, currently unmapped to any control function. Recommended as a Phase-6 coverage matrix (§G.7):

1st Level Call Over · Account Closure / Transfer to Other Branch · Accounts Opening · Branch GL Monitoring (Suspense/Transit/Proxy) · Cash Advance · Cash Evacuation Procedure · Cash Pick Up Service · Cheque and Card Requisition · Cheque and Card Issuance · Customers Telephones & Email Modification · Fixed/Call Deposit Booking and Liquidation · General Ledger Proofing · Inter Bank, Intra Bank & Bulk Credit Transfers · Interest Rate Modification on Fixed/Call Deposit · Inward and Outward Cheque · Manager's Cheque / Bank Draft · Modified Mandates · Reactivation of Dormant / Inactive Accounts · Tellering and Over the Counter Cash Management · Vault Administration
