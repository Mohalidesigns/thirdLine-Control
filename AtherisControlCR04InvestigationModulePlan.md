# Atheris Control — CR-04 · Investigation & Consequence Management Module

**Change request:** replicate the Investigation module from **ThirdLine Internal Audit** into **Atheris Control**, because internal control also investigates — fraud, staff misconduct, whistleblowing, asset misappropriation, conflicts of interest — and today the product can raise an exception and open a Speak Up case but has nowhere to run the investigation itself, name a subject, record an outcome against that person, or issue a consequence.

- **Status:** Plan — no code written yet
- **Prepared:** 26 August 2026
- **Source module:** `grcsuite/thridLine/internalaudit` — `investigation_cases` family (7 tables, 3 controllers, 3 services, 1 policy, 4 React pages + 1 shared form partial)
- **Baseline:** `atheris-control` @ 190 migrations, Laravel 13 / Inertia 3 / React 19, post CR-01 (Exception Manager), CR-02 (Internal Control Structure), CR-03 (Control Function Checklists — plan stage)
- **Decisions taken with the client:**
  1. A **separate** investigations module — not an extension of the existing `cases` (Speak Up) register
  2. **Reuse** Atheris infrastructure for evidence and reporting rather than porting ThirdLine's own tables
  3. **Full module**, delivered **core-first**, plus **control-specific extensions** ThirdLine does not have

---

## Part A — What the ThirdLine module actually is

### A.1 The seven tables

One migration (`2026_08_06_100001_create_investigation_tables.php`) creates the whole family, plus a later one (`2026_08_13_110000`) that bolts the report on.

| Table | Rows are | Notable columns |
|---|---|---|
| `investigation_cases` | one investigation | `case_reference`, `category`, `source`, `status`, `priority`, `risk_rating`, `is_confidential`, five date fields, three money fields, archive block, six narrative fields |
| `investigation_team_members` | who is on it | `user_id`, `role` (lead / investigator / reviewer / observer / SME), `assigned_at` |
| `investigation_subjects` | **who is being investigated** | `subject_type`, `name`, `staff_id`, `account_number`, `department`, `role_in_case`, `outcome` |
| `investigation_findings` | what was established | `severity`, `root_cause`, `control_failure`, `financial_impact`, `recommendation` |
| `consequence_management_actions` | **what was done about it** | `action_type` (11 values), `investigation_subject_id`, `recommended_by`/`approved_by`, `status`, `amount_recovered` |
| `investigation_activities` | the case diary | 13 `activity_type` values, `activity_date`, `nullableMorphs('linked')` |
| `investigation_evidence` | exhibits | `evidence_reference`, `file_hash`, `collected_by`, `collected_date`, `source`, `download_count` |

### A.2 The vocabularies

These are the substance of the module and they carry over almost unchanged, because they are Nigerian-bank operating vocabulary, not audit-specific vocabulary.

| Enum | Values |
|---|---|
| `category` | fraud · staff_misconduct · customer_complaint · whistleblower · regulatory_directive · asset_misappropriation · cyber_it_incident · conflict_of_interest · other |
| `source` | whistleblower · management_directive · internal_audit_finding · regulator · customer_complaint · system_alert · anonymous_tip · other |
| `status` | draft · reported · under_investigation · pending_review · completed · closed · suspended |
| `priority` | low · medium · high · critical |
| `risk_rating` | low · moderate · high · critical (null until completion) |
| subject `role_in_case` | primary_subject · witness · person_of_interest |
| subject `outcome` | exonerated · culpable · partially_culpable · inconclusive · pending |
| consequence `action_type` | query_issued · warning_letter · suspension · demotion · dismissal · restitution_recovery · prosecution_police_report · regulatory_report · training_counselling · process_change · no_action |
| consequence `status` | recommended · approved · in_progress · implemented · rejected |
| activity `activity_type` | case_created · status_changed · team_assigned · interview_conducted · evidence_collected · document_requested · site_visit · finding_added · report_issued · action_recommended · case_completed · case_archived · comment |

One caveat on the source as a UI reference: ThirdLine has **no `Investigations/Index.jsx`** — `InvestigationCaseController::index()` redirects to the audit-engagement management screen, and the register is a tab there. Atheris Control gets a proper index page (§G.3).

`InvestigationActivity::MANUAL_TYPES` splits the six a human may log (interview, evidence, document request, site visit, report issued, comment) from the seven the system writes itself. That split is worth keeping: it is what stops the diary becoming a free-text notes field.

### A.3 The state machine

`InvestigationCaseService::TRANSITIONS` is the whole workflow and it is deliberately narrow:

```
draft → reported → under_investigation → pending_review → completed → closed
                        ↕                      ↕
                    suspended ←────────────────┘
```

Three rules are enforced in the service, not the controller:

1. **`transition()` refuses `completed` outright.** Completion must go through `complete()`, which requires a `risk_rating` and a `completed_date`. This makes it structurally impossible to close an investigation without rating it.
2. **Archiving requires completed-or-closed status and a mandatory reason.** Archived cases drop out of every list, count and KPI.
3. **Every transition writes an `investigation_activities` row.** The diary is a by-product of the workflow, not an extra step someone forgets.

`complete()` also generates the draft report, inside a `try/catch` that reports the exception but does not roll back the completion. Correct instinct — report generation must never be able to strand a case in `pending_review`.

### A.4 Confidentiality

`InvestigationCase::scopeVisibleTo()` plus `InvestigationCasePolicy` implement two different regimes on one table:

- **Non-confidential:** visible to the team, the creator, or anyone with `view all investigations`.
- **Confidential:** visible to the lead, the team, and CAE-level leadership **only** — `view all investigations` does *not* open it, and every read of a confidential case is written to the activity log as `confidential_case_viewed`.

### A.5 The report

`InvestigationReportBuilder` assembles an `AuditReport` row (`report_type = 'investigation'`, `sections` JSON) from thirteen sections:

`background · scope · objectives · methodology · parties · chronology · findings_of_fact · financial_implication · root_cause · consequence_management · recommendations · conclusion · evidence_register`

Four come straight from the case's narrative rich-text fields; the other nine are *generated* from the child tables — the parties table from `subjects`, the chronology from `activities`, the evidence register from `evidence`, and so on. Regeneration is blocked once a report exists. Output is always a **draft** the investigator edits and routes through the normal review flow.

### A.6 The dashboard

`InvestigationDashboardService` — nine widgets, all SQL aggregation with no collection filtering, every one period-scoped with a previous-period comparison: KPI tiles · 12-month trend · risk distribution · financials (loss, recovery, recovery rate, by category, top cases) · consequences (by type, by status, implementation rate, overdue, subject outcomes) · by category · by entity · activity feed · ageing buckets with SLA breaches.

---

## Part B — Where this lands in Atheris Control

### B.1 The name collision, and how it resolves

Atheris Control **already has a class called `App\Models\InvestigationCase`** — but it is not this module. It is the Speak Up / whistleblowing register on the `cases` table, and it is a very different thing:

| | `cases` (Atheris, Phase 11.4) | `investigation_cases` (ThirdLine) |
|---|---|---|
| Purpose | intake and confidential handling of a report | running the investigation |
| Access | **allowlist global scope**; one named read-only override (`view all cases`, System Administrator only) | team scope + permission + leadership |
| Anonymity | one-way; `reporter_id` NULL, SHA-256 token hash only | not a concept |
| Subjects | `subject_persons` JSON blob | first-class table with per-person outcomes |
| Consequences | none | first-class table with approval |
| Financials | none | estimate / confirmed loss / recovered |
| Workflow | Received → Assessed → Under Investigation → Substantiated / Unsubstantiated / Referred → Closed | draft → reported → under_investigation → pending_review → completed → closed |

They are intake and casework. Merging them would stretch the whistleblower-protection model over routine fraud casework, and the two access models are close to inverted. On `cases`, the System Administrator holds `view all cases` — read-only oversight so no report can be invisible to the platform owner, with no power to act on a case they are not named on — and the **Control Function Head is explicitly withheld from it** (`RolePermissionSeeder.php:212`). On investigations the Control Function Head *is* the supervisor and must see the register. Merging the two would either open whistleblowing reports to a role the client deliberately excluded, or leave the head of internal control unable to supervise their own investigators. It would also strand the Speak Up module's four-layer enforcement, which `CaseConfidentialityTest` currently proves.

**Resolution — two moves, only one of which is on the critical path:**

- **C.1a (required).** The new module's aggregate is named **`Investigation`** on table **`investigations`**. No collision, no rename needed to ship, and the name matches its route (`/investigations`) and its Inertia folder (`Pages/Investigations`).
- **C.1b (Phase 0 tidy-up, not a blocker).** Rename the existing `App\Models\InvestigationCase` → **`App\Models\SpeakUpCase`** and `InvestigationCasePolicy` → `SpeakUpCasePolicy`. The table stays `cases`; only the class name moves. **20 files, ~120 references**, all mechanical:

  ```
  app/Models/{InvestigationCase,CaseNote,EntityLink,SpeakUpRevealRequest,
              SpeakUpMetadataAccessLog,SpeakUpReportMetadata}.php
  app/Policies/InvestigationCasePolicy.php
  app/Http/{Requests/CaseRequest,Controllers/CaseController,
            Controllers/SpeakUpMetadataController}.php
  app/Services/{CaseService,SpeakUpMetadataService}.php
  app/Providers/AppServiceProvider.php · routes/web.php
  database/seeders/ActivityLogSeeder.php
  tests/Feature/{CaseConfidentiality,SpeakUpMetadata,ActivityLog,
                 AnonymisingBridge,AiRetrieval}Test.php
  ```

  Do it as its own commit before any CR-04 code lands. Leaving two "investigation" concepts in one codebase with the wrong one holding the obvious name is how the next engineer writes a bug.

### B.2 What Atheris already has that ThirdLine had to build

Three of ThirdLine's seven tables should not be ported at all, because Atheris Control has better versions already:

| ThirdLine builds | Atheris already has | Verdict |
|---|---|---|
| `investigation_evidence` (hash, chain of custody, `download_count`) | **`evidence`** — polymorphic `linked_type`/`linked_id`, `checksum`, `classification`, PII categories, `retention_policy_id`, `legal_hold` with a model-level deletion guard, **plus `evidence_access_logs`** recording every View/Download/Redact with IP | **Reuse.** One evidence repository, and investigation exhibits inherit legal hold and retention for free |
| `AuditReport` + bespoke builder | **`ReportDefinition` / `ReportRun` / `ReportDesignerService`** — sections model, format engines, checksum, expiring download token, confidentiality-aware distribution, `report.generated` audit | **Reuse the pipeline, port the section builder** (§E.3) |
| ad-hoc case↔record links | **`EntityLink` + `LinkageService`** — 16 node aliases, capped graph traversal, already carries `case`, `exception`, `incident`, `complaint`, `control`, `risk`, `policy` | **Reuse.** This *is* "the investigation can be tied to a case" |

### B.3 Host conventions the port must obey

ThirdLine is single-tenant with its own helpers; Atheris is multi-tenant with house traits. Every ported class changes in the same five ways:

| ThirdLine | Atheris Control |
|---|---|
| no tenancy | `tenant_id` column + `BelongsToTenant` trait on every model |
| `use App\Traits\LogsActivity` | `use App\Models\Concerns\Auditable` (writes `audit_trail`; `auditAction()` for named domain events) |
| `InvestigationCase::generateReference()`, `INV-2026-0001` | `GeneratesReference::nextReference('INV')` → **`INV-2026-001`** (house standard §9.2 — 3-pad, tenant-scoped) |
| `protected $casts = ['description' => RichText::class]` | `HasRichText`: declare `protected array $richText = [...]`, gain a `{field}_rich` array attribute, plain column re-derived on save |
| `$user->hasAnyRole(['Super Admin','Chief Audit Executive'])` | Atheris roles: **System Administrator · Control Function Head · Control Officer · Control Owner · Line Manager · Executive Viewer** |

Also required: a `investigations` feature flag (`FeatureFlagSeeder`), route group `->middleware(['feature:investigations', 'permission:view investigations'])`, and a `navigation.js` entry gated on the same flag.

---

## Part C — Schema

Six new tables. `investigation_evidence` is dropped in favour of the shared repository; one small additive migration extends `evidence` to carry chain-of-custody fields it currently lacks.

### C.1 `investigations`

```php
Schema::create('investigations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants',
        indexName: 'fk_investigations_tenant')->cascadeOnDelete();
    $table->string('reference', 40);                    // INV-2026-001

    // ── Identity ────────────────────────────────────────────────────
    $table->string('title');
    $table->text('description')->nullable();
    $table->json('description_rich')->nullable();
    $table->enum('category', [
        'fraud', 'staff_misconduct', 'customer_complaint', 'whistleblowing',
        'regulatory_directive', 'asset_misappropriation', 'cyber_it_incident',
        'conflict_of_interest', 'process_breach', 'other',
    ]);
    $table->enum('source', [
        'whistleblowing', 'management_directive', 'control_exception',
        'control_test_failure', 'regulator', 'customer_complaint',
        'system_alert', 'anonymous_tip', 'internal_audit_finding', 'other',
    ]);

    // ── Where it sits in the control structure (CR-02) ──────────────
    $table->foreignId('control_entity_id')->nullable()->constrained('control_entities',
        indexName: 'fk_investigations_control_entity')->nullOnDelete();
    $table->foreignId('organisation_unit_id')->nullable()->constrained('organisation_units',
        indexName: 'fk_investigations_org_unit')->nullOnDelete();

    // ── Provenance: the record this was raised from ─────────────────
    $table->nullableMorphs('origin');                   // SpeakUpCase | ControlException
                                                        // | Incident | Complaint | TestInstance

    // ── Workflow ────────────────────────────────────────────────────
    $table->enum('status', ['draft', 'reported', 'under_investigation',
        'pending_review', 'completed', 'closed', 'suspended'])->default('draft');
    $table->enum('priority', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
    $table->enum('risk_rating', ['Low', 'Moderate', 'High', 'Critical'])->nullable();
    $table->boolean('is_confidential')->default(false);
    $table->boolean('confidentiality_locked')->default(false);   // §D.3 — inherited, cannot be lowered
    $table->boolean('has_sod_conflict')->default(false);         // §D.4-3 — warning, not a block
    $table->text('sod_conflict_note')->nullable();

    // ── Dates (date, not timestamp — MySQL implicit ON UPDATE) ──────
    $table->date('reported_date');
    $table->date('commenced_date')->nullable();
    $table->date('target_completion_date')->nullable();
    $table->date('completed_date')->nullable();
    $table->date('closed_date')->nullable();

    $table->foreignId('lead_investigator_id')->nullable()->constrained('users',
        indexName: 'fk_investigations_lead')->nullOnDelete();

    // ── Financial impact ────────────────────────────────────────────
    $table->decimal('estimated_financial_impact', 18, 2)->nullable();
    $table->decimal('confirmed_financial_loss', 18, 2)->nullable();
    $table->decimal('amount_recovered', 18, 2)->nullable();
    $table->string('currency', 3)->default('NGN');

    // ── Narrative (report sections) — plain + _rich pairs ───────────
    foreach (['background','scope','objectives','methodology','chronology','conclusion'] as $f) {
        $table->longText($f)->nullable();
        $table->json($f.'_rich')->nullable();
    }

    // ── Archive ─────────────────────────────────────────────────────
    $table->boolean('is_archived')->default(false);
    $table->timestamp('archived_at')->nullable();
    $table->foreignId('archived_by')->nullable()->constrained('users',
        indexName: 'fk_investigations_archiver')->nullOnDelete();
    $table->text('archive_reason')->nullable();

    $table->foreignId('created_by')->nullable()->constrained('users',
        indexName: 'fk_investigations_creator')->nullOnDelete();
    $table->foreignId('updated_by')->nullable()->constrained('users',
        indexName: 'fk_investigations_updater')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['tenant_id', 'reference'], 'uniq_investigations_tenant_ref');
    $table->index(['tenant_id', 'status']);
    $table->index(['tenant_id', 'reported_date']);
    $table->index(['tenant_id', 'is_archived', 'status']);
    $table->index('category');
    $table->index('risk_rating');
    $table->index('priority');
    $table->index('is_confidential');
    $table->index('control_entity_id');
});
```

**Departures from ThirdLine, and why:**

- `case_reference` → **`reference`**, matching `GeneratesReference` and every other Atheris model.
- `entity_id`/`department_id` → **`control_entity_id`** (the CR-02 desk or branch control unit that owns the matter) and **`organisation_unit_id`** (the department the subject sits in). ThirdLine conflated these; internal control needs both, because the investigating desk and the investigated department are rarely the same.
- **`nullableMorphs('origin')` is new.** It is the hard, queryable answer to *"tie the investigation to a case"* — see §D.2.
- **`confidentiality_locked` is new.** When an investigation is raised from a Speak Up case, confidentiality is inherited and cannot be turned off by the investigating team. See §D.3.
- **`has_sod_conflict` / `sod_conflict_note` are new.** The existing SoD tables cannot express "this lead owns the control under investigation" (§D.4-3), so the flag lives on the investigation.
- `category` gains **`process_breach`**; `source` gains **`control_exception`** and **`control_test_failure`**. Internal control's investigations most often start from a failed check, not a tip-off.
- Priority and risk-rating enums are **Title Case**, matching `ImprovementAction::PRIORITIES` and `Incident` severity in this codebase rather than ThirdLine's lowercase.

### C.2 `investigation_team_members`

Straight port plus tenancy. `role`: `lead · investigator · reviewer · observer · subject_matter_expert`. `unique(['investigation_id','user_id'])`.

### C.3 `investigation_subjects`

Straight port plus tenancy, with two additions:

```php
$table->foreignId('user_id')->nullable()->constrained('users', …)->nullOnDelete();
$table->text('outcome_rationale')->nullable();
```

`user_id` links a staff subject to a platform account where one exists — needed so the module can enforce **the subject of an investigation may not be on its team** (§D.4), which ThirdLine does not check. `outcome_rationale` exists because "culpable" against a named person with no recorded reason is not defensible at a disciplinary panel.

`subject_type`: `staff · customer · vendor · third_party · unknown` · **`system_process`** (added — asset misappropriation and process breaches often have no human subject at the start).
`role_in_case`: `primary_subject · witness · person_of_interest`.
`outcome`: `pending · exonerated · culpable · partially_culpable · inconclusive`.

**PII note.** `staff_id`, `account_number` and `name` on this table are the most sensitive columns CR-04 introduces. They are covered by the tenant scope and the visibility scope, must never appear in a dashboard aggregate or a board extract, and the retention policy that governs them is an open question (§H.4).

### C.4 `investigation_findings`

Port, plus tenancy, `reference` (`INVF-2026-001`), `HasRichText` pairs on `description`/`root_cause`/`control_failure`/`recommendation`, and **three control-specific foreign keys**:

```php
$table->foreignId('control_id')->nullable()->constrained('controls', …)->nullOnDelete();
$table->foreignId('exception_id')->nullable()->constrained('control_exceptions', …)->nullOnDelete();
$table->foreignId('improvement_action_id')->nullable()->constrained('improvement_actions', …)->nullOnDelete();
```

This is the extension that makes the module worth more here than it is in internal audit: a finding names **which control failed**, links to **the exception that failure raised**, and its recommendation becomes **a tracked improvement action** rather than a paragraph in a PDF. See §F.1.

`severity`: `Low · Moderate · High · Critical`.

### C.5 `consequence_actions`

Table renamed from `consequence_management_actions` (shorter, and Atheris does not use the `_management_` infix anywhere). Port plus tenancy and `reference` (`CON-2026-001`), with:

```php
$table->foreignId('improvement_action_id')->nullable()->constrained(…)->nullOnDelete();
$table->foreignId('evidence_id')->nullable()->constrained('evidence', …)->nullOnDelete();
$table->text('rejection_reason')->nullable();
```

`action_type` (all 11 ThirdLine values retained — they are HR-policy vocabulary and the client will recognise them): `query_issued · warning_letter · suspension · demotion · dismissal · restitution_recovery · prosecution_police_report · regulatory_report · training_counselling · process_change · no_action`.

`status`: `recommended · approved · in_progress · implemented · rejected`. Rejection now requires a reason.

`evidence_id` replaces ThirdLine's free-text `evidence` string — the query letter, the warning letter, the police report are documents, and they belong in the evidence repository under legal hold, not in a varchar.

### C.6 `investigation_activities`

Straight port plus tenancy. Keep the `TYPES` / `MANUAL_TYPES` split verbatim, and add **`confidential_view`** to `TYPES` so §D.3's confidential-read logging has somewhere to land that a user can actually see on the case timeline.

`nullableMorphs('linked')` stays — it is how an activity row points at the finding, evidence or report it concerns.

### C.7 Additive migration: `evidence` chain of custody

The shared `evidence` table records who *uploaded* a file but not who *collected* it or where it came from — a distinction that matters for investigative exhibits. One small additive migration, benefiting every module:

```php
Schema::table('evidence', function (Blueprint $table) {
    $table->foreignId('collected_by')->nullable()->after('uploaded_at')
        ->constrained('users', indexName: 'fk_evidence_collector')->nullOnDelete();
    $table->date('collected_on')->nullable()->after('collected_by');
    $table->string('collection_source')->nullable()->after('collected_on');  // "CBS extract", "CCTV, Branch 042"
    $table->text('description')->nullable()->after('collection_source');
});
```

Nothing else is needed: `checksum` already gives ThirdLine's `file_hash`, and `evidence_access_logs` already gives a far better answer than its `download_count`.

### C.8 What is deliberately **not** built

- **`investigation_evidence`** — superseded by `evidence` + `evidence_access_logs` (§C.7).
- **`audit_reports`** — superseded by `ReportDefinition` / `ReportRun` (§E.3).
- **A second linkage mechanism** — `EntityLink` carries every soft relationship (§D.2).
- **A second notes table** — the activity diary with `activity_type = 'comment'` is the notes feature.

---

## Part D — Integration: how an investigation ties to the rest of the product

### D.1 The linkage alias

Three one-line additions turn the investigation into a first-class node in the existing graph:

```php
// app/Models/EntityLink.php
NODE_TYPES[]  'investigation' => Investigation::class,
NODE_LABELS[] 'investigation' => 'Investigation',

// app/Services/LinkageService.php
DISPLAY[] 'investigation' => ['ref' => 'reference', 'title' => 'title',
                              'route' => 'investigations.show'],
```

From that moment, `investigation ↔ case ↔ exception ↔ control ↔ risk ↔ incident ↔ policy ↔ vendor` is one traversal, the Atlas page renders it with no further work, and — exactly as the `case` alias already does — a node the viewer may not see renders as *"(removed record)"* with no route. That behaviour is what makes it safe to link a confidential investigation into a graph other people can open.

### D.2 Provenance vs. relationship — the two mechanisms, and when each applies

| | `origin_type` / `origin_id` (hard morph) | `EntityLink` (graph) |
|---|---|---|
| Cardinality | exactly one, or none | many |
| Meaning | *"this investigation exists because of that record"* | *"these two records are related"* |
| Set when | at creation, immutably | any time, by anyone with the permission |
| Drives | confidentiality inheritance (§D.3), the "Raised from" banner, the report's Background section | the Atlas graph, the related-records panel |

`InvestigationService::open()` writes the morph **and** creates an `EntityLink` edge (`investigation --relates_to--> origin`) in the same transaction, so provenance is both queryable as a column and visible in the graph. The morph is the source of truth; the edge is the view.

**Raise-from entry points** (buttons that call `open()` with the origin pre-set):

| From | Button | Pre-fills |
|---|---|---|
| Speak Up case (`cases.show`) | *Open investigation* | category and source from `case_type`, `is_confidential = true` **locked** |
| Control exception (`exceptions.show`) | *Escalate to investigation* | `source = control_exception`, `control_entity_id` from the exception, finding stub linked to the failed control |
| Incident (`incidents.show`) | *Open investigation* | `source = system_alert`, `estimated_financial_impact` from the Basel loss figure |
| Complaint (`complaints.show`) | *Open investigation* | `category = customer_complaint`, `source = customer_complaint` |
| Test instance / check result | *Escalate to investigation* | `source = control_test_failure`, `control_entity_id`, failed `CheckItem` as a finding stub |
| Nothing (manual) | *New investigation* | `source = management_directive` |

### D.3 Confidentiality, and the Speak Up boundary

This is the single most important safety rule in CR-04 and it has no equivalent in ThirdLine, because ThirdLine has no whistleblowing intake to leak.

**The regime for ordinary investigations** — ported from `scopeVisibleTo()`, adapted to Atheris roles:

- **Non-confidential:** visible to the team, the lead, the creator, or anyone with `view all investigations`.
- **Confidential:** visible to the lead, the team, and **System Administrator / Control Function Head** only. `view all investigations` does **not** open it. Every read writes an `investigation_activities` row of type `confidential_view` **and** an `audit_trail` entry.

**The rule when the origin is a Speak Up case:**

1. `is_confidential` is forced **true** and `confidentiality_locked` is set. No one on the investigation can lower it.
2. The initial team is seeded from the case's `access_user_ids` — nobody gains sight of a whistleblowing matter by being added to an investigation.
3. **No reporter identity ever crosses the boundary.** `SpeakUpCase::$hidden` already hides `reporter_token_hash`; CR-04 additionally forbids `reporter_id`, the token hash, and any Tier 2 metadata from being copied into `investigations`, `investigation_subjects` or any report section. An anonymous case's investigation must be runnable end-to-end without ever resolving a person.
4. Adding a team member to an investigation whose origin is a Speak Up case **also requires** `manageAccess` on that case — one allowlist, enforced in both directions.

`InvestigationConfidentialityTest` asserts all four (§G.4), in the same spirit as the existing `CaseConfidentialityTest`.

### D.4 Segregation of duties

Three checks ThirdLine does not make, and internal control cannot do without:

1. **A subject may not be on the team.** If `investigation_subjects.user_id` matches a `investigation_team_members.user_id`, the assignment is rejected. Enforced in `InvestigationService::assignTeamMember()` *and* `addSubject()` — the conflict can arrive from either direction.
2. **The lead investigator may not approve their own consequence recommendation.** `recommended_by !== approved_by`, enforced in `ConsequenceService::approve()`.
3. **The officer who owns the failed control may not lead the investigation into it.** A warning, not a hard block — in a small branch it is sometimes unavoidable — recorded on the investigation and surfaced on the report cover.

   The existing `SodConflictRule` / `SodViolation` machinery **cannot carry this** and must not be bent to fit: it is entitlement-extract shaped. Its rules are toxic *function pairs* from a source system (`system_key`, `function_a`, `function_b` — a Finacle menu id, an Entra role, an SAP transaction code), `sod_violations.subject_identifier` is a source-system staff id explicitly documented as *not* a platform user, `rule_id` is non-nullable, the only record link is `exception_id`, and `unique(tenant_id, rule_id, subject_identifier)` would prevent flagging the same officer on two investigations. Instead the investigation carries the flag itself — `has_sod_conflict` (boolean) and `sod_conflict_note` on `investigations` (§C.1) — written by `InvestigationService::assignTeamMember()` alongside a `investigation_activities` row, and rendered on the report cover block.

---

## Part E — Services

Four services, mirroring the source's separation. Controllers stay thin: authorize → Form Request → service → `Inertia::render`.

### E.1 `InvestigationService` — lifecycle

Ported from `InvestigationCaseService`, with the same `TRANSITIONS` map and the same three structural guarantees (completion cannot bypass the rating; archive requires status + reason; every transition writes a diary row). Additions:

| Method | Notes |
|---|---|
| `open(array $data, User $actor, ?Model $origin = null)` | reference, `status = draft`, lead seeded onto the team, origin morph + `EntityLink` edge, confidentiality inheritance (§D.3), `case_created` activity |
| `transition(Investigation $i, string $to, User $actor, ?string $note)` | refuses `completed`; sets `commenced_date` on first entry to `under_investigation`, `closed_date` on `closed` |
| `complete(Investigation $i, User $actor, array $data)` | requires `risk_rating` + `completed_date`; **also requires every subject to have a non-`pending` outcome** (new — you cannot complete an investigation while a named person's status is unresolved); generates the draft report in a `try/catch` that never rolls back the completion |
| `archive` / `unarchive` | as source |
| `assignTeamMember` / `removeTeamMember` | SoD check (§D.4-1); Speak Up allowlist check (§D.3-4) |
| `addSubject` / `recordSubjectOutcome` | outcome requires `outcome_rationale` |
| `recordActivity` | manual types only from the controller; system types from the service |

### E.2 `ConsequenceService`

`recommend` → `approve` / `reject` (reason mandatory) → `markInProgress` → `implement` (records `amount_recovered`, rolls it up to the parent's total) . `approve()` enforces §D.4-2. On `action_type = 'process_change'`, spawns an `ImprovementAction` with `source_type = 'investigation'` and back-links it — see §F.1.

### E.3 `InvestigationReportBuilder` + the existing report pipeline

The section builder is **ported**; the delivery pipeline is **reused**. The thirteen ThirdLine sections map cleanly onto Atheris `ReportDefinition::SECTION_TYPES` (`cover · toc · narrative · table · chart · kpi_row · page_break · appendix · signature_block`):

| Section | Atheris section type | Source |
|---|---|---|
| Cover | `cover` | reference, title, classification, SoD warnings (§D.4-3) |
| Background | `narrative` | `background_rich` + source/reported-date preamble |
| Scope · Objectives · Methodology | `narrative` ×3 | the matching `_rich` fields |
| Parties involved | `table` | `subjects` (name, type, role, department, outcome) + interview activities |
| Chronology | `table` | `activities`, ascending |
| Findings of fact | `narrative` + `table` | `findings` with severity and linked control |
| Financial implication | `kpi_row` + `table` | estimate / confirmed loss / recovered / net; by category |
| Root cause & control failure | `narrative` | `findings.root_cause` + `control_failure`, grouped by control |
| Consequence management | `table` | `consequenceActions` with subject, status, approver |
| Recommendations | `table` | `findings.recommendation` + the improvement action each became |
| Conclusion | `narrative` | `conclusion_rich` |
| Evidence register | `appendix` / `table` | `evidence` — reference, checksum, collected by/on, source |
| Signature block | `signature_block` | lead investigator, reviewer, Control Function Head |

Delivery goes through **`ReportDesignerService::runWithDocument()`** — the exact hook the submission packs already use for content assembled elsewhere. That buys, for free: the `ReportRun` record, the checksum, the expiring download token, the `report.generated` audit entry, confidentiality-aware distribution, and PDF/DOCX/XLSX engines.

A **system `ReportDefinition`** (`code = 'INV-REPORT'`, `is_system = true`, `report_type = 'operational'`, `confidentiality = 'Confidential'`) is seeded so the report appears in the report library and can be scheduled and distributed like any other. Regeneration stays blocked once a run exists for the investigation; a *new version* is an explicit act.

### E.4 `InvestigationDashboardService`

Ported widget-for-widget, with three changes: every base query gains the tenant scope; `visibleTo($user)` is applied before aggregation, so a confidential case never contributes to a count a user should not see; and the raw `curdate()` comparisons stay (Atheris is MySQL, same as the source).

**Aggregate safety:** no widget may return a subject name, staff ID or account number. "Top cases by loss" returns reference and title only — and only for investigations the viewer can already open.

---

## Part F — Control-specific extensions

The three things this module should do here that it does not do in internal audit.

### F.1 A finding closes the loop into remediation

In ThirdLine a recommendation is text in a report. In Atheris Control it becomes tracked work:

```
InvestigationFinding.recommendation
   → ImprovementAction (source_type = 'investigation', source_id = finding.id)
       → owner, due_at, status, verification
           → back-linked via investigation_findings.improvement_action_id
```

Requires one enum widening: **`ImprovementAction::SOURCES`** gains `'investigation'` (currently `test · csa · spot_check · incident · audit · exception · survey · manual`), with the matching migration to the `source_type` column if it is constrained.

An investigation cannot move to `closed` while a finding of `High` or `Critical` severity has no improvement action — the same shape of rule CR-01 already applies to exception closure.

### F.2 A finding names the control that failed

`investigation_findings.control_id` + `exception_id` mean the Control detail page can answer *"has this control ever been implicated in an investigation?"* — a question the exception register alone cannot answer, because a fraud investigation often reveals a control that was never tested. Surfaces as a panel on `controls.show` and a column on the control's effectiveness history.

Where the investigation was raised **from** an exception, the finding's `exception_id` is pre-filled, and closing the finding feeds the CR-01 Exception Manager's escalation state rather than running beside it.

### F.3 The control structure is the entity dimension

`control_entity_id` ties every investigation to a CR-02 control desk or branch. That gives, with no extra modelling:

- **By-desk and by-branch dashboards** — which branch is generating fraud cases, which desk's controls keep failing.
- **Officer workload** — investigations alongside the CR-03 checklist task load for the same officer, so supervision sees one picture.
- **Head-office vs branch split** on every widget, matching how the client's own control function is organised.

---

## Part G — Permissions, routes, UI, tests

### G.1 Permissions

Ten permissions, named in Atheris house style:

```
view investigations              create investigations
edit investigations              delete investigations
assign investigations            complete investigations
archive investigations           view all investigations
manage investigation-consequences
view investigation-dashboard
```

Grants go in **`RolePermissionSeeder` only**. Note the divergence from the source: ThirdLine ships an idempotent permission-grant *migration* (`2026_08_06_100002`) alongside its seeder, but Atheris Control has **no permission-grant migration anywhere** — every permission in the product is seeded, and each role calls `syncPermissions()`. Adding a grant migration here would be a new pattern and would fight `syncPermissions()` on the next seed. Existing installs pick the new permissions up by re-running the seeder.

| Role | Grant |
|---|---|
| **System Administrator** | all ten |
| **Control Function Head** | all ten |
| **Control Officer** | view · create · edit · complete · assign · manage-consequences · dashboard |
| **Control Owner** | view (team-scoped only — no `view all investigations`) · dashboard |
| **Line Manager** | view (team-scoped only) |
| **Executive Viewer** | `view investigation-dashboard` only — aggregates, never a case |
| **Speak Up Reveal Approver** | none. Reveal approval is a separate duty and must stay that way |

`delete` remains draft-only, as in the source.

### G.2 Routes

```php
Route::middleware(['feature:investigations', 'permission:view investigations'])->group(function () {
    // Before the resource, so 'dashboard' is not swallowed as {investigation}.
    Route::get('investigations/dashboard',        [InvestigationDashboardController::class, 'index'])->name('investigations.dashboard');
    Route::get('investigations/dashboard/export', [InvestigationDashboardController::class, 'export'])->name('investigations.dashboard.export');

    Route::resource('investigations', InvestigationController::class);

    Route::prefix('investigations/{investigation}')->name('investigations.')->group(function () {
        Route::post('status',                 [InvestigationController::class, 'updateStatus'])->name('status');
        Route::post('complete',               [InvestigationController::class, 'complete'])->name('complete');
        Route::post('subjects',               [InvestigationSubjectController::class, 'store'])->name('subjects.store');
        Route::put('subjects/{subject}',      [InvestigationSubjectController::class, 'update'])->name('subjects.update');
        Route::post('findings',               [InvestigationFindingController::class, 'store'])->name('findings.store');
        Route::put('findings/{finding}',      [InvestigationFindingController::class, 'update'])->name('findings.update');
        Route::post('findings/{finding}/improvement', [InvestigationFindingController::class, 'raiseImprovement'])->name('findings.improvement');
        Route::post('consequences',           [InvestigationConsequenceController::class, 'store'])->name('consequences.store');
        Route::put('consequences/{action}',   [InvestigationConsequenceController::class, 'update'])->name('consequences.update');
        Route::post('activities',             [InvestigationController::class, 'storeActivity'])->name('activities.store');
        Route::post('evidence',               [InvestigationEvidenceController::class, 'store'])->name('evidence.store');
        Route::post('report',                 [InvestigationReportController::class, 'generate'])->name('report.generate');

        Route::middleware('permission:assign investigations')->group(function () {
            Route::post('team',               [InvestigationController::class, 'storeTeamMember'])->name('team.store');
            Route::delete('team/{member}',    [InvestigationController::class, 'removeTeamMember'])->name('team.destroy');
            Route::post('archive',            [InvestigationController::class, 'archive'])->name('archive');
            Route::post('unarchive',          [InvestigationController::class, 'unarchive'])->name('unarchive');
        });
    });
});
```

Evidence upload delegates to the existing `EvidenceController` logic with `linked_type = Investigation::class`; download and access logging are unchanged, which is the point of reusing the repository.

### G.3 Pages

`resources/js/Pages/Investigations/` — `Index.jsx · Create.jsx · Edit.jsx · Show.jsx · Dashboard.jsx`, composed from the existing Atheris primitives (no new dependencies; ThirdLine's JSX is a reference for layout, not a file to copy, because the component libraries differ).

`Show.jsx` is tabbed: **Overview · Subjects · Findings · Consequences · Evidence · Diary · Report**. A confidential investigation renders a persistent classification banner and a note that the view has been logged.

`resources/js/config/navigation.js`: `{ label: 'Investigations', to: 'investigations.index', match: '/investigations', icon: Gavel, feature: 'investigations', permission: 'view investigations' }`, placed in the **`exceptions`** group ("Exceptions & Issues") immediately after **Cases**. Note that Cases (`exceptions` group) and Incidents (`risk` group) do not currently share a group — Investigations belongs with Cases, because that is where a user goes looking for a matter under investigation.

### G.4 Tests

New `tests/Feature/` files, matching the 72 already there:

| File | Asserts |
|---|---|
| `InvestigationWorkflowTest` | every legal transition; every illegal one rejected; `completed` unreachable via `transition()`; completion blocked without a risk rating; completion blocked while a subject outcome is `pending`; archive requires completed/closed + reason |
| `InvestigationConfidentialityTest` | non-confidential team scoping; confidential invisible to `view all investigations`; confidential visible to Control Function Head; every confidential read logged; **all four Speak Up boundary rules (§D.3)** |
| `InvestigationSodTest` | subject cannot be teamed (both directions); recommender cannot approve; control-owner-as-lead raises a `SodViolation` warning, not a block |
| `InvestigationConsequenceTest` | recommend → approve → implement; rejection requires a reason; `amount_recovered` rolls up; `process_change` spawns a back-linked `ImprovementAction` |
| `InvestigationReportTest` | all thirteen sections build; regeneration blocked; report generation failure does not roll back completion; no reporter identity appears in any section of a Speak-Up-origin report |
| `InvestigationDashboardTest` | tenant isolation; confidential cases excluded from another user's aggregates; no subject PII in any widget payload; period and previous-period arithmetic |
| `InvestigationLinkageTest` | origin morph + edge written in one transaction; unviewable node renders as "(removed record)" |

---

## Part H — Phasing, effort, open questions

### H.1 Phase 0 — Preparation (0.5 day)

Rename `InvestigationCase` → `SpeakUpCase` and its policy (§B.1b), as one mechanical commit with the existing test suite green before and after. Not on the critical path, but do it first.

### H.2 Phase 1 — Core casework (5–6 days)

Tables `investigations`, `investigation_team_members`, `investigation_subjects`, `investigation_findings`, `investigation_activities` · the `evidence` chain-of-custody migration · `Investigation` + four child models · `InvestigationService` · `InvestigationPolicy` with the visibility scope · permissions (seeder) + feature flag · `InvestigationController`, `InvestigationSubjectController`, `InvestigationFindingController`, evidence wiring · linkage alias · `Index/Create/Edit/Show` pages · `InvestigationWorkflowTest`, `InvestigationConfidentialityTest`, `InvestigationSodTest`, `InvestigationLinkageTest`.

**End of Phase 1 the module is usable end-to-end:** raise from a case or an exception, assemble a team, name subjects, record findings against controls, attach evidence, complete with a rating, archive.

### H.3 Phase 2 — Consequence management, dashboard, report (4–5 days)

`consequence_actions` table + `ConsequenceService` + `InvestigationConsequenceController` + tab · `InvestigationDashboardService` + controller + `Dashboard.jsx` + CSV export · `InvestigationReportBuilder` + `INV-REPORT` system definition + generate-on-complete · `InvestigationConsequenceTest`, `InvestigationReportTest`, `InvestigationDashboardTest`.

### H.4 Phase 3 — Control extensions (2–3 days)

Finding → `ImprovementAction` (§F.1, incl. the `SOURCES` widening) · control implication panel on `controls.show` (§F.2) · by-desk / by-branch dashboard cuts and officer workload (§F.3) · raise-from buttons on exception, incident, complaint and test-instance pages · notification events (`investigation.assigned`, `investigation.overdue`, `investigation.consequence-due`, `investigation.completed`) seeded into `NotificationEventSeeder`.

**Total: 12–15 developer days**, plus review.

### H.5 Open questions for the client

1. **Investigation vs. Incident — where does a fraud loss get recorded?** `incidents` already carries Basel-style loss capture. Proposal: the **incident** owns the loss figure for operational-risk reporting; the **investigation** owns the confirmed loss and recovery for consequence purposes; where an investigation has an incident origin, the figures are reconciled on the incident page and flagged if they diverge. **Needs confirmation** — it determines which number the CBN return uses.
2. **Who approves a consequence?** ThirdLine has an `approved_by` column but no defined approver. In a Nigerian bank this is normally the Disciplinary Committee or HR, not internal control. Does Atheris need a **consequence approval workflow** (routing, quorum) or is a single named approver with the `manage investigation-consequences` permission sufficient for v1? Assumed: single approver.
3. **Retention of subject PII.** How long may `investigation_subjects.staff_id` / `account_number` be kept after an exonerated outcome? Proposal: exonerated subjects' identifying fields are purged at a configurable interval (default 24 months) via the existing `RetentionPolicy` machinery, with the finding and the outcome retained in anonymised form. **Needs a policy decision**, not an engineering one.
4. **Does an anonymous whistleblowing case ever produce a distributable report?** §D.3 guarantees no reporter identity crosses into the investigation, but a narrow report is still capable of identifying a reporter by circumstance. Proposal: Speak-Up-origin reports are `Board` confidentiality by default and cannot be scheduled for automatic distribution.
5. **Branch scope.** Should a branch control officer see investigations at other branches (`view all investigations`), or only their own? Assumed: **own branch only**, via `control_entity_id` scoping added to the visibility scope. Confirm before the permission grants are seeded.
6. **`suspended` — does it stop the clock?** ThirdLine treats suspended as still-open for ageing. If a case is suspended pending a police report for six months, that distorts average-days-to-close. Proposal: suspended days are excluded from ageing, and the dashboard shows suspended as its own bucket.

---

## Part I — Summary of files

| | New | Modified |
|---|---|---|
| Migrations | 6 create + 1 alter (`evidence`) + 1 `improvement_actions.source_type` enum widening (confirmed: it **is** an enum) | — |
| Models | `Investigation`, `InvestigationTeamMember`, `InvestigationSubject`, `InvestigationFinding`, `ConsequenceAction`, `InvestigationActivity` | `EntityLink`, `ImprovementAction`, `Evidence`, `InvestigationCase`→`SpeakUpCase` (no change to `SodConflictRule`/`SodViolation` — see §D.4-3) |
| Services | `InvestigationService`, `ConsequenceService`, `InvestigationReportBuilder`, `InvestigationDashboardService` | `LinkageService` |
| Policies | `InvestigationPolicy` | `InvestigationCasePolicy`→`SpeakUpCasePolicy` |
| Controllers | `InvestigationController`, `InvestigationSubjectController`, `InvestigationFindingController`, `InvestigationConsequenceController`, `InvestigationEvidenceController`, `InvestigationReportController`, `InvestigationDashboardController` | `CaseController`, `ExceptionController`, `IncidentController`, `ComplaintController` (raise-from) |
| Requests | `StoreInvestigationRequest`, `UpdateInvestigationRequest`, `CompleteInvestigationRequest`, `ArchiveInvestigationRequest`, `InvestigationSubjectRequest`, `InvestigationFindingRequest`, `InvestigationConsequenceRequest` | — |
| Pages | `Investigations/{Index,Create,Edit,Show,Dashboard}.jsx` | `config/navigation.js`, `Cases/Show.jsx`, `Exceptions/Show.jsx`, `Incidents/Show.jsx`, `Controls/Show.jsx` |
| Seeders | `InvestigationDemoSeeder`, `INV-REPORT` report definition | `RolePermissionSeeder`, `FeatureFlagSeeder`, `NotificationEventSeeder` |
| Tests | 7 feature test files | — |
