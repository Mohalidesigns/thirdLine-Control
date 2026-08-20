# Atheris Control — CR-02 · Internal Control Structure, Assurance Engines & Investigations
### Client change request: three control sub-units, a Nigerian-bank control universe, risk register, repeated-failure trend analysis, testing templates, mapping engine, cross-functional controls, dependency map, and Internal Control Investigations

**How to use this document**

1. Open a fresh Claude Code session in the `thirdLine-Control` repo — one session per prompt.
2. Paste **Part A — Master Context Brief** from `Atheris-Control-v2-Claude-Code-Prompts.md` first. Every time.
3. Then paste **exactly one prompt** from Part B below, in order: **CR2-A → CR2-B → CR2-C → CR2-D**. Never two in one session.
4. Let it run to completion, verify the Definition of Done, commit, close the session.

This is a change request against the shipped **Phase 7–17 + CR-01** baseline, not a new
roadmap phase. It slots after CR-01 and depends on nothing that is unbuilt. CR2-A is the
spine — the other three address the structure it creates, so it must run first. CR2-B and
CR2-C are independent of each other. CR2-D runs last.

---

## Scope map — what the client asked for vs. what the codebase already has

| # | Client ask | What exists today | What CR-02 does |
|---|---|---|---|
| 0 | Internal Control organised as 3 sub-units (Head Office Control, Information Systems Control, Branch Control) with control entities under each, like the internalaudit product's Audit Management entities | `organisation_units` tree (Head Office / Branch / Department / Subsidiary types, heads, regulatory profile); `entities` legal-entity register (Phase 16); **no control-universe taxonomy** | **CR2-A** builds `control_units` + `control_entities` (the control universe), seeded with the Nigerian bank taxonomy, bridged to `organisation_units` — never replacing them |
| 1 | Internal control risk register | Full Phase-10 risk module (scales, assessments, appetite, treatments) — enterprise-wide, not scoped to the control function | **CR2-B** adds an Internal Control register view scoped to control entities, with risks raised from control failures |
| 2 | Trend analysis incl. repeated control failure | Trend *widgets* (`exception_trend`, `test_completion_trend`), `MetricService::trend()`, a `trend` CCM rule type — scattered, no engine, no repeated-failure detection | **CR2-B** builds the trend engine: configurable `trend_rules`, persisted `trend_signals`, a daily analyser, repeated-failure detection across tests, CCM and exceptions |
| 3 | Control approaches / testing templates | `test_scripts` + `check_items` per control; `controls.is_template`; no template *library* | **CR2-C** adds a testing-template library keyed to the control-entity taxonomy, instantiable onto any control |
| 4 | Continuous control monitoring, automatic | Phase 12 CCM is complete (11 rule types, scheduled runs, auto-exceptions) | **CR2-B** wires CCM output into the trend engine and the control-entity view — integration, not a rebuild |
| 5 | Regulatory / internal policy & procedures mapping engine | Regulatory side exists (`frameworks` → `framework_requirements` → `control_requirement_map`, maker–checker); policy module exists; **no control↔internal-SOP mapping** | **CR2-C** adds `control_policy_map` and a single mapping workbench showing both sides plus gap analysis |
| 6 | Cross-functional controls (risks that span departments) | Nothing first-class — a control has one `unit_id` | **CR2-A** adds `control_stakeholders` (owner / co-owner / contributor / consulted per unit) |
| 7 | Control dependency map with business-process impact | Only a generic `depends_on` edge in the polymorphic `entity_links` graph — no typed model, no cycle detection, no impact analysis | **CR2-C** adds `control_dependencies` + `control_process_map`, cycle detection, and failure-impact snapshots on exceptions |
| 8 | Internal Control Investigation (replicate internalaudit's Investigation module) | Phase 11.4 case module (`cases`, allowlist access, anonymity, `case_notes`) — a case *register*, not an investigation *workbench* | **CR2-D** extends it into the full workbench: plan tasks, interviews, exhibits with chain of custody, findings & outcomes, versioned sign-off report |

---

# PART B — THE PROMPTS

## CR2-A — CONTROL UNIVERSE: THREE SUB-UNITS & CONTROL ENTITIES
### Paste everything inside the fence

```
CR2-A — INTERNAL CONTROL STRUCTURE: THREE SUB-UNITS & THE CONTROL UNIVERSE

OBJECTIVE
The client wants the Internal Control function organised the way the ThirdLine
Internal Audit product organises its Audit Management entities: a structure of
sub-units, each holding a register of the entities the second line oversees.
Three sub-units, seeded, tenant-extensible:

  1. Head Office Control       — head office departments: Treasury, Human
                                 Resources, Corporate Services, Finance &
                                 Accounts, Operations, Legal, Procurement, …
  2. Information Systems Control — IS control domains: Database Management,
                                 Network Security, Backup & Recovery, Disaster
                                 Recovery, Vulnerability Management, Cloud
                                 Platform, Operating Server Infrastructure,
                                 End User Computing, Application Control, ATM,
                                 Change Management, End of Day Transactions
                                 Cutoff, …
  3. Branch Control            — the list of bank branches, and under each
                                 branch its control activities: Cash
                                 Management, Teller Operations, Vault, ATM,
                                 POS, Customer Account Opening, KYC, Funds
                                 Transfer, Clearing & Settlements, E-Business
                                 Channels, …

Build the control universe: the sub-units, the control-entity register under
each, branch auto-provisioning from the org tree, attachment of controls to
entities, cross-functional stakeholders for controls whose risk does not
belong to one department, and the navigation and dashboards that make the
three sub-units the front door of the product for a Nigerian bank.

READ FIRST
  DEVELOPMENT_STANDARD.md
  app/Models/OrganisationUnit.php and migrations
    2026_08_05_150005_create_organisation_units_table.php,
    2026_08_15_100003_add_subsidiary_to_organisation_unit_types.php
      ← the operational tree. Branch Control READS it; nothing here replaces it.
  app/Models/Entity.php + app/Services/EntityService.php (Phase 16)
      ← the LEGAL-ENTITY register. Do not confuse it with control entities and
        do not touch it. Three org concepts will now coexist: organisation_units
        (operational tree), entities (legal/consolidation), control_entities
        (what the second line oversees). Keep the naming razor-sharp everywhere.
  app/Models/Control.php + the create_controls migration
      ← controls already carry unit_id, process_id, owner_id, is_key_control.
  app/Models/BusinessProcess.php, app/Services/LinkageService.php
  app/Services/ContentPackInstaller.php     ← how seeded taxonomies ship
  database/seeders/RolePermissionSeeder.php
  resources/js/Pages/Entities/              ← the tree/register UI patterns
  the single sidebar nav config (grouped collapsible navigation)
Do not restate what you read. Read it, then build.

WHAT ALREADY EXISTS — REUSE, DO NOT REBUILD
  - organisation_units: parent/children tree, type enum (Head Office, Branch,
    Department, Subsidiary), head_user_id, regulatory profile. Branch Control's
    branch list is DERIVED from units of type Branch — never a second list of
    branches maintained by hand.
  - business_processes hanging off units.
  - controls with unit_id/process_id/owner_id — those stay the canonical single
    owner. Stakeholders ADD units, they do not replace ownership.
  - entity_links / LinkageService — the universal graph. Control-entity
    attachment is a first-class pivot below, not a graph edge.
  - The component library: DataTable, FilterBar, StatCard, Modal, StatusBadge,
    SeverityBadge, PageHeader, EmptyState, ConfirmDialog, ProgressBar,
    FlashNotification. RichTextEditor/RichTextViewer + the HasRichText concern
    for every new long-form field ({field}_rich + plain-text mirror).

DATA MODEL

  control_units                  -- the sub-units of the Internal Control function
    id, tenant_id, code (HOC, ISC, BRC…), name,
    domain enum(head_office, information_systems, branch, other) default other,
    description text NULL, head_user_id NULL → users,
    sequence unsigned, is_active bool default true
    unique(tenant_id, code)
    Seed the three; a tenant may add more (e.g. Subsidiary Control). Behaviour
    switches on `domain`, NEVER on the name string.

  control_entities               -- the control universe rows
    id, tenant_id, control_unit_id → control_units,
    parent_id NULL → self,            -- branches nest their activities here
    reference (CE-…, GeneratesReference), name, description text NULL,
    entity_kind enum(department, domain, branch, activity),
    organisation_unit_id NULL → organisation_units,  -- the bridge to the ops tree
    business_process_id NULL → business_processes,
    owner_id NULL → users,            -- the second-line relationship officer
    risk_rating enum(Critical,High,Medium,Low) NULL,  -- planning priority
    review_frequency enum(monthly,quarterly,semiannual,annual) NULL,
    last_reviewed_at NULL, next_review_due_at NULL,
    is_template bool default false,   -- branch-activity template rows only
    sequence unsigned, is_active bool default true
    unique(tenant_id, control_unit_id, parent_id, name)
    index(tenant_id, control_unit_id, entity_kind),
    index(tenant_id, organisation_unit_id)

  control_entity_control         -- which controls an entity oversees
    id, tenant_id, control_entity_id, control_id, is_key bool default false
    unique(tenant_id, control_entity_id, control_id)
    A control may sit under MANY entities (ATM lives in Information Systems
    Control and under every branch). This pivot is how every register, test,
    exception and trend view scopes to the structure — no entity_id columns
    sprayed onto existing tables in this prompt.

  control_stakeholders           -- cross-functional controls (client ask #6)
    id, tenant_id, control_id → controls,
    organisation_unit_id → organisation_units,
    role enum(owner, co_owner, contributor, consulted),
    user_id NULL → users,             -- named contact in that unit, optional
    notes NULL
    unique(tenant_id, control_id, organisation_unit_id)
    Exactly one row per control may carry role=owner, and it must agree with
    controls.unit_id — the service keeps them in lockstep. Everything else is
    additive: co-owners and contributors see the control in their unit's
    register and are notified when it fails.

BUILD

  CR2A.1 ControlStructureService + seeding
    - Service owns create/update/deactivate for units and entities, template
      instantiation and the branch sync below. Controllers stay thin.
    - ControlStructureSeeder seeds per tenant: the three control_units; the
      Head Office departments and Information Systems domains listed in the
      OBJECTIVE as control_entities (entity_kind department / domain); the
      branch ACTIVITY TEMPLATE as is_template=true activity rows under Branch
      Control (Cash Management, Teller Operations, Vault, ATM, POS, Customer
      Account Opening, KYC, Funds Transfer, Clearing & Settlements, E-Business
      Channels). Lists live in the seeder/content pack, not in PHP constants
      scattered through services.
    - Where a seeded entity matches an existing organisation_unit by name
      (e.g. Treasury), link organisation_unit_id automatically; otherwise leave
      the bridge NULL for the admin to set.

  CR2A.2 Branch auto-provisioning
    - control-structure:sync-branches artisan command, registered in
      routes/console.php: for every organisation_unit of type Branch, ensure a
      control_entity (entity_kind branch) exists under the Branch Control unit,
      then ensure every ACTIVE template activity exists under that branch
      (copy-on-write: instantiated rows are independent of the template).
    - IDEMPOTENT: a second run creates nothing. ADD-ONLY: a new template
      activity propagates to branches on the next sync; deleting or editing a
      template NEVER deletes or rewrites instantiated rows.
    - An observer on OrganisationUnit provisions a newly created Branch
      immediately — a branch opened in Kano on Monday appears under Branch
      Control on Monday.

  CR2A.3 Attaching controls
    - Entity screen action "Attach controls": pick from the library, singly or
      in bulk by category/unit, set is_key per attachment. Detach is allowed
      only while the entity has no open exception or in-flight test against
      that control through this pivot — otherwise deactivate.
    - Controls/Show gains a "Control structure" panel listing the entities the
      control is attached to.

  CR2A.4 Cross-functional stakeholders
    - Controls/Show gains a Stakeholders panel: add/remove units with a role.
    - The unit-scoped control register (and My Work) now includes controls
      where my unit is a co_owner or contributor, badged "Shared".
    - New seeded notification_events, through NotificationDispatcher only:
        control.stakeholder.added
        control.shared.exception_raised   -- co-owner units are told when a
                                             shared control fails
      Wire the second into ExceptionService at raise time: notify the
      stakeholder units' heads (and named user_id where set) for co_owner rows.

  CR2A.5 The front door
    resources/js/Pages/ControlStructure/Index.jsx — three sub-unit cards with
      counts (entities, attached controls, open exceptions, overdue reviews),
      plus any tenant-added units.
    ControlStructure/Unit.jsx — the register under one unit. Head Office and
      IS: a flat, sequenced entity list. Branch Control: a searchable branch
      list (name, code, state, head) that drills into the branch's activities.
    ControlStructure/Entity.jsx — the entity profile: bridge links, owner,
      rating, review cadence, and tabs: Controls (the pivot), Exceptions and
      Tests (both derived through attached controls), Risks / Trend /
      Investigations (render as empty-state placeholders wired up by CR2-B and
      CR2-D — build the tab shells now so those prompts only fill them).
    Sidebar: a "Control Structure" group in the single nav config, permission-
      gated, above the flat Controls library entry.

  CR2A.6 Dashboards
    - New widget source (app/Dashboards/Sources) exposing: entities by risk
      rating, structure coverage (% of active entities with ≥1 key control
      attached), branches by open-exception count (the branch heat list),
      reviews overdue. Register them in WidgetRegistry.

PERMISSIONS
  New: 'view control-structure', 'manage control-structure',
       'attach control-entities', 'manage control-stakeholders'.
  Seed them in RolePermissionSeeder — NOT in a migration (syncPermissions()
  wipes migration-time grants). Control Function Head and Control Officer get
  all four; Control Owner and Line Manager get view; System Administrator gets
  manage control-structure but NOT attach/stakeholders (structure admin is not
  control assignment).

BUSINESS RULES
  R-A  control_entities BRIDGE organisation_units; they never replace them.
       Branch Control derives branches from the org tree — creating a branch
       control entity with no organisation_unit_id and entity_kind=branch is a
       validation error. (Phase 16's R8 precedent: layer on top, move nothing.)
  R-B  Behaviour switches on control_units.domain, never on unit names.
  R-C  Template instantiation is copy-on-write and add-only, as CR2A.2.
  R-D  One owner per control across controls.unit_id and control_stakeholders;
       the service enforces agreement.
  R-E  Every new table: tenant_id + BelongsToTenant, Auditable; references via
       GeneratesReference. Tenant scoping on every query.
  R-F  Long-form fields (description, notes) follow the HasRichText convention.

TESTS  (tests/Feature — each a named test)
  - Seeder produces the three units, the HO departments, the IS domains and the
    branch activity template; re-running it duplicates nothing.
  - sync-branches provisions a branch and its activities; a second run creates
    zero rows; a template activity added later reaches existing branches on the
    next sync; editing a template does not touch instantiated activities.
  - Creating an organisation_unit of type Branch auto-provisions its control
    entity via the observer.
  - Attach/detach pivot uniqueness; detach blocked while an open exception
    exists on the attachment path.
  - Second role=owner stakeholder row is rejected; owner row must match
    controls.unit_id.
  - A co-owner unit's register lists the shared control; a consulted unit's
    does not; exception on a shared control notifies the co-owner head through
    the dispatcher.
  - 403s for each new permission; tenant isolation on all four new tables.

DELIVERABLES
  4 create migrations, 3 models (ControlUnit, ControlEntity,
  ControlStakeholder — pivot may stay implicit) with Auditable/BelongsToTenant/
  GeneratesReference, ControlStructureService, ControlStructureController,
  Form Requests for every write, ControlUnitPolicy/ControlEntityPolicy, the
  sync-branches command + OrganisationUnit observer, ControlStructureSeeder +
  demo data (3 demo branches fully provisioned), the React pages and nav
  group, widget source registration, seeder updates (RolePermissionSeeder,
  NotificationEventSeeder), the tests above, a README "Internal Control
  Structure (CR-02)" section in the established phase-section shape, and BRD
  §5 additions FR-13.1..FR-13.8 for the structure.

DEFINITION OF DONE
  - php artisan migrate:fresh --seed runs clean; suite green; npm run build.
  - Demo path: open Control Structure → three sub-units show seeded entities →
    Branch Control lists the demo branches → drill into a branch → its
    activities are there → attach a control to Cash Management → add a
    co-owner unit to that control → raise an exception on it → the co-owner
    head is notified → the entity page shows the exception through the pivot.
  - No behaviour keyed to a unit/entity NAME anywhere in the diff.
  - Commit on the session's designated claude/* branch.
```

---

## CR2-B — INTERNAL CONTROL RISK REGISTER & TREND ANALYSIS
### Paste everything inside the fence

```
CR2-B — INTERNAL CONTROL RISK REGISTER, TREND ENGINE & CCM WIRING

OBJECTIVE
Three of the client's module updates, one data spine:
  1. An INTERNAL CONTROL RISK REGISTER — the second line's own register,
     scoped to the control universe built in CR2-A, fed by control failures.
  2. TREND ANALYSIS that shows, among others, REPEATED CONTROL FAILURE — a
     real engine with configurable rules and persisted signals, not more
     dashboard widgets.
  3. CONTINUOUS CONTROL MONITORING feeding both automatically — Phase 12's CCM
     already runs on schedule and raises exceptions; its output must now land
     in the trend engine and the control-entity view without a human carrying
     it there.

READ FIRST
  DEVELOPMENT_STANDARD.md
  app/Models/Risk.php + create_risks migration and the later risk migrations
      ← note source enum(Local,NexusRisk), entity/process links, appetite and
        treatment machinery. The risk module is COMPLETE — scope it, feed it,
        do not fork it.
  app/Services/RiskAssessmentService.php, AppetiteService.php
  app/Services/RuleEngineService.php       ← ::trend() already does
        period-over-period movement for ONE rule run; the engine below is
        cross-source and persistent — different job, keep both.
  app/Services/MonitoringService.php, app/Models/MonitoringFinding.php
  app/Models/{TestInstance,CheckResult,EffectivenessRating,ControlException,
              MetricBreach}.php            ← the failure sources
  app/Support/SqlDialect.php               ← date grouping that works on
                                             MySQL AND SQLite (tests)
  app/Dashboards/Sources/*.php, routes/console.php
  database/migrations/2026_08_08_150011_… and 2026_08_09_100013_…
      ← the enum-extension pattern, if you extend risks.source
  CR2-A's control_entities / control_entity_control — the scoping spine.
Do not restate what you read. Read it, then build.

WHAT ALREADY EXISTS — REUSE, DO NOT REBUILD
  - The whole Phase-10 risk module: scales, multi-dimensional assessments,
    second-line review, heatmaps, appetite with breach escalation, treatments.
    The internal control register is a SCOPED VIEW plus a feed — zero new risk
    mechanics.
  - CCM: data_sources → snapshots → monitoring_rules → runs → findings, with
    auto_create_exception. Runs are already scheduled. Do not add a scheduler.
  - risk_control_map for risk↔control linkage.
  - NotificationDispatcher; the escalation matrix; ReportService /
    ExcelExportService; WidgetRegistry.

DATA MODEL

  ALTER risks (one migration, additive, with down())
    control_entity_id NULL → control_entities,
    register enum(enterprise, internal_control) default enterprise,
    raised_from_type NULL, raised_from_id NULL,   -- morph: trend_signal,
                                                  -- control_exception,
                                                  -- monitoring_finding,
                                                  -- investigation_finding
    last_control_failure_at NULL
    index(tenant_id, register, control_entity_id)
    Existing rows stay enterprise. Nothing else on risks changes.

  trend_rules                    -- R1: no threshold lives in PHP
    id, tenant_id, name,
    signal_type enum(repeated_control_failure, deteriorating_effectiveness,
                     recurring_exception, repeat_monitoring_finding,
                     kri_breach_pattern),
    scope enum(per_control, per_control_entity, per_unit) default per_control,
    lookback_days unsigned, min_occurrences unsigned,
    severity_map json,           -- occurrence bands → severity
    auto_raise_risk bool default false,
    auto_notify bool default true,
    is_active bool default true
    unique(tenant_id, name)
    Seed sensible defaults (e.g. repeated_control_failure: 3 occurrences in
    365 days, per_control) — defaults live in the SEEDER, not the service.

  trend_signals                  -- one persisted row per detected pattern
    id, tenant_id, trend_rule_id → trend_rules, reference (TS-…),
    signal_type,                 -- denormalised from the rule at detection
    control_id NULL → controls, control_entity_id NULL → control_entities,
    organisation_unit_id NULL → organisation_units,
    window_start date, window_end date,
    occurrence_count unsigned,
    occurrences json,            -- [{type, id, ref, occurred_at}] — the proof,
                                 -- immutable once written except appends
    severity enum(Critical,High,Medium,Low),
    fingerprint char(64),        -- sha256(rule|scope ids|signal_type); the
                                 -- idempotency key across runs
    status enum(New, Under Review, Risk Raised, Action Raised, Dismissed)
      default New,
    first_detected_at, last_evaluated_at,
    reviewed_by NULL → users, reviewed_at NULL,
    dismissal_reason text NULL,
    risk_id NULL → risks, improvement_action_id NULL → improvement_actions
    unique(tenant_id, fingerprint)
    index(tenant_id, status, severity), index(tenant_id, control_entity_id)

  ALTER monitoring_rules (additive, with down())
    control_entity_id NULL → control_entities
    A rule may target an entity directly (e.g. End of Day Transactions Cutoff);
    rules without it resolve their entity through the control pivot.

BUILD

  CR2B.1 TrendAnalysisService
    - Evaluates every active trend_rule over its lookback window against the
      failure sources: check_results marked Fail (via test_instances →
      control), monitoring_findings, control_exceptions, effectiveness_ratings
      that stepped down, metric_breaches (kri_breach_pattern).
    - Groups by the rule's scope, resolves control_entity through
      control_entity_control (or monitoring_rules.control_entity_id), computes
      occurrence_count, maps severity from severity_map.
    - UPSERT BY FINGERPRINT: a pattern already signalled updates
      occurrence_count / occurrences / last_evaluated_at / severity on the SAME
      row. It never duplicates, and it never resurrects a Dismissed signal
      unless NEW occurrences arrived after the dismissal — then it reopens to
      New with the dismissal preserved in the activity trail.
    - Date arithmetic through SqlDialect so the suite runs on SQLite.

  CR2B.2 trends:analyse
    - Daily artisan command in routes/console.php. Idempotent within a day.
    - auto_notify rules dispatch trend.signal.detected (new seeded
      notification_event) to the control entity owner and the Control Function
      Head, severity-gated, through NotificationDispatcher only.
    - auto_raise_risk rules create a DRAFT internal-control risk (see CR2B.3)
      linked back to the signal. Never an active risk without a human.

  CR2B.3 The Internal Control Risk Register
    - Risks index gains a register switch (Enterprise | Internal Control) and,
      on the internal control register, a structure filter (sub-unit → entity
      tree from CR2-A).
    - "Raise risk" from a trend_signal or a control_exception pre-fills the
      draft: register=internal_control, control_entity_id, raised_from morph,
      linked controls via risk_control_map. The raiser cannot also perform the
      second-line review of the same risk — reuse the module's existing
      review SoD, do not invent a parallel one.
    - The CR2-A entity page's Risks tab now lists the entity's risks with
      inherent/residual chips.

  CR2B.4 CCM wiring
    - Monitoring findings and CCM-raised exceptions flow into CR2B.1 with no
      new plumbing beyond the source queries — verify with a feature test that
      a monitoring rule failing N times across N runs yields ONE signal with
      occurrence_count N.
    - Monitoring/Rules UI: optional control-entity picker on the rule form.
    - Seed ONE demo CCM rule against the End of Day Transactions Cutoff entity
      so the Nigerian demo path is real.

  CR2B.5 Surfaces
    resources/js/Pages/Trends/Index.jsx — the signal register: filters
      (sub-unit, entity, control, signal type, severity, status), stat cards
      (new, under review, repeated-failure count this quarter, risks raised).
    Trends/Show.jsx — the occurrence timeline (each occurrence deep-links to
      its source test/finding/exception), the review actions (Under Review,
      Raise Risk, Raise Improvement Action, Dismiss with mandatory reason).
    ControlStructure/Entity.jsx — fill the Trend tab shell from CR2-A.
    Dashboard widgets: repeated failures by entity, top failing controls,
      signal ageing. Register in WidgetRegistry.
    Reports: "Trend & Repeated Control Failure" PDF (ReportService blade) and
      XLSX (ExcelExportService), filterable by sub-unit and period.

PERMISSIONS
  New: 'view trend-signals', 'review trend-signals', 'configure trend-rules'.
  Seed in RolePermissionSeeder. Control Function Head and Control Officer
  review; System Administrator configures rules but does NOT review or
  dismiss signals.

BUSINESS RULES
  R-A  Every detection threshold is read from trend_rules. A reviewer finding
       a lookback or count in PHP must move it to the table.
  R-B  Signals are append-mostly: occurrences are never edited or removed;
       dismissal requires a reason; reopening preserves the dismissal record.
  R-C  SoD: the owner of a failing control cannot dismiss a signal on that
       control; the raiser of a risk cannot second-line review it.
  R-D  Auto-raised risks are always Draft.
  R-E  Fingerprint uniqueness guarantees one row per pattern per tenant.
  R-F  Tenant scoping everywhere; the analyse command iterates tenants with
       withoutGlobalScope('tenant') + explicit tenant_id, the way
       EscalationService already does.

TESTS
  - Three Fail check_results on one control across three instances inside the
    lookback → one signal, occurrence_count 3; a fourth failure updates the
    same row; a control one failure short of min_occurrences yields nothing.
  - Changing min_occurrences on the rule changes the next run's outcome —
    nothing hard-coded.
  - trends:analyse twice in a day: no duplicate signals, no duplicate
    notifications.
  - Dismissed signal stays dismissed on re-evaluation with no new occurrences;
    reopens on a genuinely new occurrence.
  - Control owner gets 403 dismissing their own control's signal.
  - Raise-risk creates a Draft internal_control risk with the morph and
    control_entity set; the enterprise register does not show it; the
    internal control register does.
  - Monitoring-sourced occurrences produce one signal (CR2B.4).
  - kri_breach_pattern picks up metric_breaches.
  - Widget/report smoke tests; tenant isolation on trend_rules/trend_signals;
    audit_trail rows for review, raise, dismiss, reopen.

DELIVERABLES
  3 migrations (2 create, 2 alters — risks, monitoring_rules), TrendRule and
  TrendSignal models, TrendAnalysisService, TrendSignalController +
  TrendRuleController, Form Requests, TrendSignalPolicy, the trends:analyse
  command, notification event seeds, trend-rule default seeds + demo signals,
  the React pages and entity-tab fill, widgets, the PDF/XLSX report, README
  section, BRD FR-14.x additions, tests above.

DEFINITION OF DONE
  - migrate:fresh --seed clean; suite green including existing risk and
    monitoring tests; npm run build.
  - Demo path: seeded repeated failures on a branch's Cash Management control
    → trends:analyse → signal appears with 3 linked occurrences → reviewer
    raises a risk → it lands in the Internal Control register under that
    branch entity → dashboard widget and PDF both show it.
  - Commit on the session's designated claude/* branch.
```

---

## CR2-C — TESTING TEMPLATES, MAPPING ENGINE & DEPENDENCY MAP
### Paste everything inside the fence

```
CR2-C — CONTROL APPROACHES & TESTING TEMPLATES, REGULATORY/POLICY MAPPING
        ENGINE, CONTROL DEPENDENCY MAP

OBJECTIVE
Three of the client's module updates:
  1. CONTROL APPROACHES / TESTING TEMPLATES — a library of standard testing
     templates keyed to the control-entity taxonomy (a Cash Management
     template, a Network Security template…), instantiable onto any control,
     with the testing approach (inquiry, observation, inspection,
     reperformance, data analytics) recorded on every script.
  2. A MAPPING ENGINE — one workbench where a control is mapped to EITHER a
     regulatory control framework (exists today) OR an internal policy / SOP
     (new), with coverage and gap analysis both ways.
  3. A CONTROL DEPENDENCY MAP — internet banking depends on MFA, API security,
     transaction monitoring…; when one critical control fails, people must see
     which controls and BUSINESS PROCESSES are affected.

READ FIRST
  DEVELOPMENT_STANDARD.md
  app/Models/{TestScript,CheckItem,TestInstance}.php + their migrations
      ← test_scripts.control_id is currently NOT NULL; templates change that.
  app/Services/TestingService.php, app/Services/VersioningService.php
  app/Models/{Framework,FrameworkRequirement,ControlRequirementMap}.php and
  app/Services/MappingService.php          ← the regulatory side and its
        maker–checker verification. The policy side MIRRORS this pattern.
  app/Models/{Policy,PolicySection}.php
  app/Models/CompensatingControl.php       ← feeds the single-point-of-failure
  app/Models/EntityLink.php + LinkageService
      ← the generic depends_on edge stays for everything else; control→control
        dependencies get the typed table below as the single source of truth —
        do NOT double-write them into entity_links.
  app/Services/ExceptionService.php        ← the raise path you will hook
  CR2-A's control_entities; CR2-B's trend engine (read-only context)
Do not restate what you read. Read it, then build.

WHAT ALREADY EXISTS — REUSE, DO NOT REBUILD
  - test_scripts + check_items with versioning and approval; keep both.
  - control_requirement_map and its verification workflow — the template for
    control_policy_map, and untouched itself.
  - content_packs — templates must be shippable in packs.
  - The exception lifecycle and CR-01 Exception Manager — the impact snapshot
    decorates the exception; it changes no lifecycle rule.

DATA MODEL

  ALTER test_scripts (one migration, additive, with down())
    control_id → NULLABLE,             -- a template belongs to no control
    is_template bool default false,
    control_entity_id NULL → control_entities,  -- taxonomy node the template
                                                -- is written for
    template_source enum(content_pack, tenant) NULL,
    approaches json NULL               -- subset of: inquiry, observation,
                                       -- inspection, reperformance,
                                       -- data_analytics
    CHECK-equivalent rule in the Form Request: is_template=false requires
    control_id; is_template=true forbids it.

  control_policy_map               -- the internal-SOP side of the engine
    id, tenant_id, control_id → controls,
    policy_id → policies, policy_section_id NULL → policy_sections,
    mapping_type enum(implements, supports, governed_by),
    coverage enum(full, partial) default full, notes NULL,
    verification_status + verified_by/verified_at — mirror the columns and
      maker–checker flow of control_requirement_map exactly,
    created_by → users
    unique(tenant_id, control_id, policy_id, policy_section_id)

  control_dependencies             -- typed control→control edges
    id, tenant_id, control_id → controls,          -- the DEPENDENT control
    depends_on_control_id → controls,
    criticality enum(critical, important, informational) default important,
    description NULL, created_by → users, is_active bool default true
    unique(tenant_id, control_id, depends_on_control_id)
    Self-edges rejected in the Form Request; cycles rejected in the service.

  control_process_map              -- which processes a control protects
    id, tenant_id, control_id → controls,
    business_process_id → business_processes,
    criticality enum(critical, supporting) default supporting
    unique(tenant_id, control_id, business_process_id)

  ALTER control_exceptions (additive, with down())
    impact_snapshot json NULL
    -- {computed_at, dependents:[{control_id, ref, title, criticality, depth}],
    --  processes:[{process_id, name, criticality}],
    --  entities:[{control_entity_id, name}]}
    A DENORMALISED SNAPSHOT taken at raise time. Never recomputed in place —
    the graph as it stood when the control failed is the record.

BUILD

  CR2C.1 Testing template library
    - Library screen (Templates tab inside the Testing area): templates
      grouped by the control-entity taxonomy; create/edit a template exactly
      like a script (title, objective, sampling guidance, approaches, check
      items), same approval states.
    - "Create test script from template" on a control (and from the CR2-A
      entity page for any attached control): deep-copies the template and its
      check_items onto the control as a new Draft script version. The copy is
      INDEPENDENT — later template edits never touch instantiated scripts.
    - Seed starter templates for the demo taxonomy: Cash Management, Teller
      Operations, Vault, ATM, Network Security, Backup & Recovery, Change
      Management, End of Day Transactions Cutoff — 4-6 check items each,
      template_source=content_pack.
    - approaches renders as badges on scripts and instances; add an approach
      filter to the testing register.

  CR2C.2 The mapping engine
    - Controls/Show mapping panel becomes the WORKBENCH: two columns —
      Regulatory (existing control_requirement_map rows) and Internal Policy /
      SOP (new control_policy_map rows) — same add / verify / reject
      interactions on both, maker–checker on both (the mapper cannot verify
      their own mapping; mirror MappingService's existing flow).
    - Gap analysis screen (Mappings/Gaps.jsx):
        controls with NO mapping of either kind;
        framework requirements (per adopted framework) with no control;
        active policies with no implementing control.
      Each list exports XLSX and deep-links to fix the gap.
    - Coverage stat on the control register and the CR2-A entity page:
      mapped-both / regulatory-only / policy-only / unmapped.

  CR2C.3 The dependency map
    - ControlDependencyService: addEdge validates tenant, self-edge, duplicate
      and CYCLE — walk depends_on transitively; a cycle rejects with the path
      named in the validation message (A → B → C → A).
    - downstream(control): BFS over ACTIVE edges returning dependents with
      depth and edge criticality; upstream(control) the mirror. Both cap at a
      sane depth (from config, not a literal in the service body).
    - Controls/Show "Dependencies" tab: upstream list, downstream list, and a
      layered map view (depth columns, criticality-coloured edges — the
      existing component library + SVG, no new graphing dependency), plus the
      processes the control protects (control_process_map editor).
    - RAISE-TIME HOOK: when an exception is raised on a control that has
      dependents or critical process mappings, ExceptionService stores
      impact_snapshot (downstream to the cap + mapped processes + affected
      CR2-A entities via the pivot). Exceptions/Show renders an "Impact"
      panel: what this failure touches. For Critical/High exceptions where a
      downstream edge or process mapping is CRITICAL, dispatch the new seeded
      notification_event control.dependency.impacted to the dependent
      controls' owners and the process-owning unit heads — through
      NotificationDispatcher only, deduplicated per user.
    - Single-point-of-failure report: controls that are depended on by ≥N
      others (N from config) with criticality=critical and NO active
      compensating_control — PDF + XLSX, plus a dashboard widget.

PERMISSIONS
  New: 'manage testing-templates', 'map control-policies',
       'verify control-policy-mappings', 'manage control-dependencies'.
  Seed in RolePermissionSeeder. Mapper and verifier roles must be able to
  differ (maker–checker); System Administrator gets none of the verify rights.

BUSINESS RULES
  R-A  A template is never executable: test_instances can only be generated
       from a script attached to a control. Enforce in TestingService.
  R-B  Instantiation is copy-on-write, as CR2C.1.
  R-C  Maker–checker on BOTH mapping tables; the creator of a mapping cannot
       verify it. No admin bypass.
  R-D  control_dependencies is the single source of truth for control→control
       dependency; nothing writes depends_on control pairs into entity_links.
  R-E  Cycles are impossible by construction (service-enforced), including via
       edge REACTIVATION — reactivating a soft-deactivated edge revalidates.
  R-F  impact_snapshot is immutable once written.
  R-G  Every threshold (BFS depth cap, SPOF fan-in N) lives in config or a
       settings table, never inline.
  R-H  Tenant scoping everywhere; both ends of every edge and mapping must
       belong to the same tenant — validated, not assumed.

TESTS
  - Template with control_id rejected; script without control_id rejected;
    instantiation copies items, later template edit leaves the script alone;
    generating instances from a template is impossible.
  - Mapping maker–checker: creator 403s on verify (both tables); duplicate
    mapping rejected; gap lists shrink when a mapping is added.
  - Cycle A→B→C→A rejected naming the path; self-edge rejected; cross-tenant
    edge rejected; reactivation that would close a cycle rejected.
  - downstream() returns correct depths across 3 levels; respects is_active.
  - Raising a High exception on a control with dependents writes
    impact_snapshot with dependents, processes and entities; a control with
    no edges writes none; the snapshot survives later edge changes unchanged.
  - control.dependency.impacted notifies each affected owner once.
  - SPOF report lists a critical hub without a compensating control and drops
    it once one is added.
  - Tenant isolation on all new tables; audit_trail rows on map/verify/edge
    add/deactivate.

DELIVERABLES
  5 migrations (3 create, 2 alter), models (ControlPolicyMap,
  ControlDependency, ControlProcessMap), ControlDependencyService, template
  support in TestingService, MappingService extension, controllers + Form
  Requests + policies for templates/mappings/dependencies, notification event
  seed, template seeds, the React surfaces above, SPOF + gap reports (PDF/
  XLSX), dashboard widgets, README section, BRD FR-15.x additions, tests.

DEFINITION OF DONE
  - migrate:fresh --seed clean; suite green; npm run build.
  - Demo path: instantiate the Cash Management template onto a branch control
    → map that control to a CBN framework requirement AND an internal SOP
    section, second user verifies both → build Internet Banking depends-on
    MFA / API Security / Transaction Monitoring edges → raise a Critical
    exception on MFA → the exception shows the impact panel naming Internet
    Banking's process and dependents, and owners are notified → gap and SPOF
    reports render.
  - Commit on the session's designated claude/* branch.
```

---

## CR2-D — INTERNAL CONTROL INVESTIGATIONS
### Paste everything inside the fence

```
CR2-D — INTERNAL CONTROL INVESTIGATIONS (PARITY WITH THE INTERNALAUDIT
        INVESTIGATION MODULE)

OBJECTIVE
The client's headline differentiator for the Nigerian market: when a control
failure smells like fraud, collusion or a serious breach — a teller shortage
that repeats, an EOD cutoff bypass, a vault variance — the second line opens a
FORMAL INVESTIGATION: a plan, a team, interviews, exhibits under chain of
custody, findings with root cause and quantified amounts, a signed-off report,
and outcomes that land back in the exception manager, the risk register and
the trend engine. The ThirdLine Internal Audit product has an Investigation
module; replicate its BEHAVIOUR for internal control on this codebase.

PARITY REFERENCE — ThirdLine Internal Audit
Mirror the internalaudit product's Investigation module BEHAVIOUR — the
lifecycle (plan → fieldwork → evidence → findings → report → closure), its
confidentiality posture and its sign-off discipline. Do NOT mirror its schema:
this build is Laravel 13 + Inertia + the Atheris conventions, on top of the
EXISTING Phase 11.4 case module. The person running this prompt should have
the internalaudit repo open beside this one; where this prompt and that
module disagree on workflow states or artefacts, follow internalaudit and
record the deviation in the README section.

READ FIRST
  DEVELOPMENT_STANDARD.md
  app/Models/InvestigationCase.php          ← table `cases`. THE FOUNDATION.
      Allowlist-only global scope ('view all cases' = read-only oversight,
      audited); anonymous cases non-de-anonymisable (auditsAnonymously());
      TRANSITIONS state machine. Every rule here SURVIVES this prompt intact.
  app/Models/CaseNote.php, app/Services/CaseService.php,
  app/Policies/InvestigationCasePolicy.php, resources/js/Pages/Cases/
  tests/Feature/CaseConfidentialityTest.php ← the bar the new tables must meet
  app/Models/{Evidence,EvidenceAccessLog}.php + EvidenceService
      ← exhibits wrap evidence rows; access logging exists.
  app/Services/ReportService.php + resources/views/reports/*.blade.php
  app/Services/ExceptionService.php, ImprovementAction, CR2-A control
  structure, CR2-B risk register (raised_from morph accepts
  investigation_finding)
  database/migrations/2026_08_08_150011_… / 2026_08_09_100013_…
      ← the enum-extension pattern for cases.case_type
Do not restate what you read. Read it, then build.

WHAT ALREADY EXISTS — REUSE, DO NOT REBUILD
  - The `cases` register, allowlist scope, anonymity machinery, case_notes,
    the /speak-up intake, WhistleblowingController. Control investigations are
    cases of a NEW TYPE with a workbench attached — not a parallel module with
    a second confidentiality model.
  - evidence + evidence_access_logs — exhibits reference evidence; files and
    classification and retention stay there.
  - NotificationDispatcher; audit trail; HasRichText for long-form fields.

DATA MODEL

  ALTER cases (additive, with down(); extend the case_type MySQL enum with
  'control_investigation' following the established enum-extension pattern)
    control_entity_id NULL → control_entities,
    control_id NULL → controls,
    related_exception_id NULL → control_exceptions,
    related_monitoring_finding_id NULL → monitoring_findings,
    related_trend_signal_id NULL → trend_signals,
    opened_from enum(exception, monitoring_finding, trend_signal, incident,
                     complaint, speak_up, management_request) NULL,
    amount_involved decimal(18,2) NULL, currency char(3) NULL

  investigation_tasks            -- the plan, as executable steps
    id, tenant_id, case_id → cases, sequence unsigned, title,
    description text NULL, assigned_to NULL → users, due_at NULL,
    status enum(Pending, In Progress, Completed, Cancelled) default Pending,
    completed_at NULL, completed_by NULL → users, outcome text NULL
    index(tenant_id, case_id, status)

  investigation_interviews
    id, tenant_id, case_id, reference (IVI-…),
    interviewee_name,                       -- may be a non-user (customer,
    interviewee_user_id NULL → users,       --  vendor staff, ex-employee)
    interviewee_unit_id NULL → organisation_units,
    mode enum(in_person, virtual, written) default in_person,
    scheduled_at NULL, conducted_at NULL, conducted_by NULL → users,
    summary text NULL,                      -- rich text
    statement_evidence_id NULL → evidence,  -- the signed statement file
    is_confidential bool default true

  investigation_exhibits         -- chain of custody over evidence
    id, tenant_id, case_id, exhibit_no,     -- EXH-001 sequential PER CASE
    evidence_id → evidence, description NULL,
    obtained_from NULL, obtained_at,
    custodian_id → users,                   -- CURRENT custodian
    custody_log json,                       -- append-only:
                                            -- [{at, from_user_id, to_user_id,
                                            --   reason, recorded_by}]
    integrity_hash char(64) NULL            -- sha256 of the evidence file at
                                            -- exhibit creation
    unique(tenant_id, case_id, exhibit_no)

  investigation_findings
    id, tenant_id, case_id, reference (IVF-…), title, description text,
    category enum(fraud, control_failure, policy_breach, process_gap,
                  human_error, collusion, external_event),
    severity enum(Critical,High,Medium,Low),
    root_cause text NULL, root_cause_category enum(people, process,
      technology, governance, external) NULL,
    amount_involved decimal(18,2) NULL, amount_recovered decimal(18,2) NULL,
    recommendation text NULL,
    subject_persons json NULL,
    status enum(Draft, Confirmed, Withdrawn) default Draft,
    confirmed_by NULL → users, confirmed_at NULL,
    outcome enum(disciplinary_referral, recovery, control_remediation,
                 training, process_change, no_action) NULL,
    outcome_exception_id NULL → control_exceptions,
    outcome_improvement_action_id NULL → improvement_actions,
    outcome_case_id NULL → cases,           -- the spawned disciplinary case
    risk_id NULL → risks

  investigation_reports          -- the signed-off instrument
    id, tenant_id, case_id, version_no unsigned default 1,
    status enum(Draft, Under Review, Approved, Issued) default Draft,
    background text NULL, scope text NULL, methodology text NULL,
    findings_summary text NULL, conclusion text NULL,
    recommendations text NULL,              -- all rich text
    prepared_by → users, reviewed_by NULL → users, reviewed_at NULL,
    approved_by NULL → users, approved_at NULL, issued_at NULL,
    document_id NULL → documents            -- the archived generated PDF
    unique(tenant_id, case_id, version_no)

  ACCESS RULE FOR EVERY NEW TABLE: rows are reachable ONLY through their case.
  Policies delegate to InvestigationCasePolicy/grantsAccessTo, and every query
  runs through the case relationship so the allowlist global scope applies.
  No new table gets its own wider scope. This is the CaseConfidentialityTest
  bar, extended.

BUILD

  CR2D.1 Origination
    - "Open investigation" actions on Exceptions/Show, MonitoringFinding,
      Trends/Show and Incidents/Show, permission-gated.
    - CaseService::openControlInvestigation(source, User $opener, array attrs):
      creates the case (case_type control_investigation, opened_from + related
      FK set, control/control_entity resolved from the source, severity
      carried over), allowlist = opener + named lead investigator, writes the
      audit trail and a case_note recording the origination.
    - The SOURCE record shows a linked-investigation chip — but only its
      existence and reference, and only when the case confidentiality is
      Standard. Restricted and Highly Restricted cases surface NOTHING on the
      source record to non-allowlisted users. The chip itself deep-links; the
      allowlist decides whether the link resolves.

  CR2D.2 The workbench
    - Cases/Show for control_investigation cases gains tabs: Plan (tasks:
      sequence, assignee, due, status, outcome), Interviews, Exhibits,
      Findings, Report. Existing tabs (notes, details) unchanged. All content
      allowlist-gated server-side, not hidden client-side.
    - Task board respects TRANSITIONS: fieldwork tabs are read-only until the
      case reaches Under Investigation.

  CR2D.3 Chain of custody
    - Creating an exhibit from an evidence row computes integrity_hash
      (sha256 of the stored file) and seeds custody_log with the obtaining
      entry. Custody TRANSFER appends {at, from, to, reason, recorded_by};
      the log is append-only — no update or delete path exists in the service
      or any request.
    - "Verify integrity" action re-hashes the file and compares; a mismatch
      badges the exhibit TAMPER FLAG and writes an audit_trail entry. Every
      exhibit open is logged through evidence_access_logs, as evidence already
      does.

  CR2D.4 Findings & outcomes
    - Confirm requires root_cause + recommendation (and amount_involved when
      category is fraud or recovery is expected). Confirmed_by must differ
      from the finding's creator.
    - Outcome wiring, each through the owning service (never raw creates):
        control_remediation → raise a control exception via ExceptionService
          (or link an existing one) — from there CR-01's Exception Manager
          escalation loop takes over;
        disciplinary_referral → spawn a linked case of the existing
          'disciplinary' type with a FRESH allowlist (HR lead, not the
          investigation team by default);
        recovery → amount_recovered tracked on the finding;
        training / process_change → improvement action via ImprovementService.
    - "Raise risk" on a confirmed finding creates a Draft internal-control
      risk (CR2-B: register=internal_control, raised_from investigation
      _finding).
    - Confirmed findings with category control_failure or fraud count as
      occurrences in CR2-B's trend engine (add the source query to
      TrendAnalysisService).

  CR2D.5 Report & sign-off
    - One live report per case, versioned: regeneration after Issued creates
      version_no + 1 (the issued version is immutable and stays archived).
    - Draft → Under Review → Approved → Issued. SoD: prepared_by, reviewed_by
      and approved_by are THREE DIFFERENT USERS — reviewer and approver must
      each hold 'approve investigation-reports' minus/plus per PERMISSIONS,
      and none may equal the preparer. Issuing generates the PDF (blade under
      resources/views/reports, house report styling: case summary, plan,
      interviews held, exhibit schedule with hashes, findings, conclusion,
      recommendations, sign-off block) and archives it as a document linked
      via document_id.
    - Issued LOCKS the case's findings: no edit, no new finding, no
      confirmation changes without a new report version.

  CR2D.6 Closure gating
    - A control_investigation case cannot transition to Closed while: any
      task is Pending or In Progress; any finding is Draft; findings exist
      but no report version is Issued; any Confirmed finding lacks an
      outcome. ValidationException names each blocker.
    - Substantiated requires ≥1 Confirmed finding; Unsubstantiated requires
      zero Confirmed findings.
    - The existing TRANSITIONS map and the anonymity rules are UNCHANGED.

  CR2D.7 Register & reporting
    - Cases/Index gains a Control Investigations view (filter case_type),
      allowlist-scoped as ever: stat cards (open, by category, total amount
      involved vs recovered, average days to close), structure filter
      (sub-unit → entity from CR2-A), branch drill.
    - Fill the CR2-A entity page's Investigations tab shell.
    - Board/ARCC extract PDF: investigations opened/closed in period, amounts,
      substantiation rate, repeat entities — NO restricted detail: reference,
      entity, category, severity, status, amounts only.
    - Dashboard widgets registered in WidgetRegistry, computed WITHOUT
      bypassing the allowlist for row-level display; aggregate counts may use
      an explicit, documented aggregate query the way oversight reporting
      already handles cases.

PERMISSIONS
  New: 'open control-investigations', 'manage investigation-plan',
       'record interviews', 'manage exhibits',
       'confirm investigation-findings', 'approve investigation-reports'.
  Seed in RolePermissionSeeder. Permissions gate ACTIONS; the allowlist gates
  ROWS — both must pass, and 'view all cases' oversight remains read-only.
  System Administrator gets no investigation permissions by default.

BUSINESS RULES
  R-A  The allowlist is inviolate on every new table — reachable only through
       the case. No admin bypass, no role-name escape hatch.
  R-B  Anonymity survives: an investigation opened from an anonymous speak-up
       case keeps auditsAnonymously() behaviour end to end.
  R-C  custody_log is append-only; integrity_hash immutable after creation.
  R-D  Findings immutable after report issue (new version to amend).
  R-E  Three-person SoD on the report: preparer ≠ reviewer ≠ approver.
  R-F  Finding creator cannot confirm their own finding.
  R-G  Amounts always carry currency; no float arithmetic on money.
  R-H  Outcome objects are created through their owning services so their own
       lifecycles, references and notifications fire normally.
  R-I  Tenant scoping everywhere; audit_trail on open, task completion,
       interview, exhibit create/transfer/verify, finding confirm, report
       state changes, closure.

TESTS
  - Allowlist: a tenant user off the allowlist gets nothing from every new
    table via direct query and 403/404 via routes; oversight permission reads
    but cannot write; extend CaseConfidentialityTest's approach.
  - Enum extension migration up AND down safe with existing case rows.
  - Origination from an exception links both ways; Restricted case shows no
    chip to a non-allowlisted viewer of the exception.
  - Custody transfer appends; no route or service path mutates an existing
    log entry; integrity verify flags a tampered file.
  - Finding creator 403s on confirm; confirm without root cause rejected.
  - Report SoD: preparer 403s on review and approve; two-role user still
    cannot occupy two seats; issue locks findings; post-issue edit creates
    version 2 and leaves version 1 archived.
  - Closure gating throws naming open tasks / draft findings / unissued
    report / outcome-less findings; passes when all clear.
  - control_remediation outcome creates a real exception through
    ExceptionService (reference, activities, notifications fire).
  - Confirmed fraud finding shows up as a trend occurrence (CR2-B).
  - Anonymous-origin investigation writes unattributed audit rows.
  - Tenant isolation on all five new tables.

DELIVERABLES
  6 migrations (5 create, 1 alter incl. the enum extension), 5 models
  (InvestigationTask, InvestigationInterview, InvestigationExhibit,
  InvestigationFinding, InvestigationReport) with the house traits,
  InvestigationService (workbench) + CaseService extension, controllers +
  Form Requests + policies delegating to the case allowlist, the report blade
  + generation, notification event seeds (investigation.opened,
  investigation.task.assigned, investigation.report.pending_review,
  investigation.report.issued — dispatcher only), RolePermissionSeeder
  update, demo seed (one full investigation on a demo branch: teller cash
  shortage → interviews → exhibits → confirmed fraud finding → issued report
  → exception + disciplinary referral), the React tabs and register view,
  widgets, board extract PDF, README section (including any recorded
  deviations from the internalaudit module), BRD FR-16.x additions, tests.

DEFINITION OF DONE
  - migrate:fresh --seed clean; suite green including
    CaseConfidentialityTest and AnonymisingBridgeTest untouched; npm run build.
  - Demo path end to end on seeded data: repeated cash shortage exception on
    a branch → open investigation → plan tasks → record two interviews →
    attach two exhibits with custody transfers → confirm a fraud finding with
    amounts → report prepared, reviewed, approved, issued as PDF →
    control_remediation raises an exception, disciplinary referral spawns its
    case → investigation closes → the branch entity page, the register, the
    trend engine and the board extract all show it.
  - Commit on the session's designated claude/* branch.
```

---

## Notes for the person running this

**Why four prompts and not one.** CR2-A creates the structure every other prompt
addresses; B, C and D each fit a single session with room to breathe, and each ends in a
demonstrable state. Running order is A first, then B and C in either order, D last (its
outcome wiring uses B's register and the CR-01 exception loop, and its register hangs off
A's entities).

**Cut points if a session runs long.**
- CR2-A: cut after CR2A.4 — run CR2A.5/6 (UI + dashboards) as a follow-up session.
- CR2-B: cut after CR2B.3 — CCM wiring and surfaces (CR2B.4/5) stand alone.
- CR2-C: the three builds are separable; dependency map (CR2C.3) is the one the client
  will demo — if forced to choose, run it first, not last.
- CR2-D: cut after CR2D.5 — closure gating and the register (CR2D.6/7) are a clean
  second session.

**Three org concepts now coexist — keep the language straight in every UI string.**
`organisation_units` is the operational tree (who works where), `entities` is the Phase-16
legal-entity register (consolidation, residency), `control_entities` is the new control
universe (what the second line oversees). The prompts enforce the bridges; the copy in
screens and docs must never call one by another's name.

**CR2-D deliberately extends `cases` rather than building a parallel module.** The
Phase 11.4 allowlist and anonymity machinery is the hardest-won code in the product;
a second investigation module would mean a second confidentiality model to keep honest.
If the client insists on a fully separate register, it is a filter and a nav entry, not a
schema change — but confirm before assuming.

**What to confirm with the client before running:**
- The final seed lists: which Head Office departments, which IS domains (is "End of Day
  Transactions Cutoff" a standing control entity, a CCM rule, or both — CR2-A/B seed it
  as both), and the standard branch activity set — and whether branch activities vary by
  branch tier (cash centre vs. mini-branch). The seeder takes lists, so this is data, not
  design, but the demo should show *their* bank.
- Default trend thresholds: CR2-B seeds "3 occurrences in 365 days" for repeated control
  failure — confirm against the client's internal control policy.
- Whether cross-functional stakeholder units should be able to *block* changes to a shared
  control (approval) or only see and be notified (built). Approval is a scope change.
- Access to the internalaudit repository while running CR2-D, so the parity check is
  against the real module, not memory. If access is impossible, run CR2-D anyway — the
  behaviour spec above is self-sufficient — and walk the client through the result against
  their expectations of the audit product.
- Which regulatory packs the mapping engine demo should lead with (CBN circulars are the
  obvious wedge for the Nigerian banks and fintechs this update is aimed at).
