# SecondLine — Atheris Control Solution

A second-line-of-defence platform for banks and fintechs: define, test, rate, and monitor internal controls, and track exceptions through to **verified closure**. Sibling product to ThirdLine (Internal Audit), built on the same [ThirdLine Development Standard](DEVELOPMENT_STANDARD.md).

- **Stack:** Laravel 13 · Inertia.js · React 18 · Tailwind 3 · Spatie Permission · Ziggy · MySQL
- **Design system:** AEGIS — Navy `#0B1F3A`, Gold `#C9A227` (tokens in `resources/css/app.css`)
- **BRD:** [Control-Solution-BRD-v1.0.md](Control-Solution-BRD-v1.0.md)

## Getting started

```bash
composer setup          # install, .env, key, migrate, npm install, build
php artisan migrate:fresh --seed
composer dev            # server + queue + logs + Vite
```

Demo logins (password: `password`):

| Email | Role |
|---|---|
| `admin@secondline.test` | System Administrator |
| `cfh@secondline.test` | Control Function Head |
| `officer1@secondline.test`, `officer2@secondline.test` | Control Officer |
| `owner1@secondline.test`, `owner2@secondline.test` | Control Owner |
| `manager@secondline.test` | Line Manager |
| `exec@secondline.test` | Executive Viewer |

## Tenancy model

Deployment is **branch-per-client**: each installation serves one tenant, but every
domain table carries `tenant_id` as defence in depth. The
`App\Models\Concerns\BelongsToTenant` trait adds a global query scope (rows are
filtered to the authenticated user's tenant) and auto-fills `tenant_id` on create.
The `Tenant` model holds licence key, data-residency, and settings. Reference data
shared across tenants (system control categories, the default rating matrix) uses
`tenant_id = NULL`.

## RBAC matrix

Roles follow BRD §4, seeded in `RolePermissionSeeder` with `"<verb> <resource>"`
permissions. Enforcement is layered: **route middleware → controller authorize →
policy → query scoping**, mirrored to the UI via shared Inertia props.

| Capability | Sys Admin | CF Head | Control Officer | Control Owner | Line Mgr | Exec Viewer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Define controls / build scripts | ✓ | ✓ | ✓ | — | — | — |
| Approve controls / scripts / ratings (maker≠checker) | ✓ | ✓ | — | — | — | — |
| Execute tests, raise exceptions | ✓ | ✓ | ✓ | — | — | — |
| Review tests (tester ≠ reviewer) | ✓ | ✓ | — | — | — | — |
| Remediate exceptions | ✓ | ✓ | ✓ | own only | — | — |
| **Verify & close exceptions** | see note | ✓ | — | — | — | — |
| Approve extensions / accept risk | ✓ | ✓ | — | — | — | — |
| Spot checks | ✓ | ✓ | ✓ | — | — | — |
| Dashboards / reports | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Manage users, settings, escalation matrices | ✓ | matrices only | — | — | — | — |
| Manage SSO (maker ≠ checker, second admin approves) | ✓ | — | — | — | — | — |
| View / export audit log | ✓ | ✓ | — | — | — | — |
| Feature flags, security policy, branding | ✓ | — | — | — | — | — |
| Own MFA, notification preferences, saved views, search | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View frameworks & requirement trees | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Author / edit a tenant framework | ✓ | ✓ | — | — | — | — |
| Map a control to a requirement | ✓ | ✓ | ✓ | — | — | — |
| **Approve a control-to-requirement mapping (maker ≠ checker)** | see note | ✓ | — | — | — | — |
| View the obligation register & compliance calendar | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Author / edit obligations, assign to entities | ✓ | ✓ | — | — | — | — |
| Record a regulatory submission | ✓ | ✓ | ✓ | own only | — | — |
| **Accept / reject a submission (filer ≠ reviewer)** | see note | ✓ | — | — | — | — |
| Waive an obligation instance | ✓ | ✓ | — | — | — | — |
| Log & impact-assess a regulatory publication | ✓ | ✓ | ✓ | — | — | — |
| **Sign a regulatory change off as Actioned (assessor ≠ signer)** | see note | ✓ | — | — | — | — |
| Install regulatory content packs | ✓ | — | — | — | — | — |
| View control distributions | ✓ | ✓ | ✓ | own entity | ✓ | ✓ |
| Distribute a group control to entities | ✓ | ✓ | ✓ | — | — | — |
| Acknowledge a distribution / progress tasks | — | — | — | assigned owner | — | — |
| **Approve an entity's decline of a control (requester ≠ approver)** | — | ✓ | — | — | — | — |
| View campaigns (CSA / attestation / survey) | ✓ | ✓ | ✓ | assigned | assigned | ✓ |
| Create & manage campaigns, build questionnaires | ✓ | ✓ | ✓ | — | — | — |
| **Approve a campaign (creator ≠ approver)** | see note | ✓ | ✓ | — | — | — |
| Respond to assigned campaign / attest / answer survey | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| **Review a CSA response (respondent ≠ reviewer)** | see note | ✓ | ✓ | — | — | — |
| **Apply a CSA-proposed rating to a control (CFH only, never own control)** | — | ✓ | — | — | — | — |
| View attestation register | ✓ | ✓ | ✓ | own only | own only | ✓ |
| View documents (published; confidential by role list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Create documents & manage folders | ✓ | ✓ | ✓ | — | — | — |
| **Approve / publish a document (owner ≠ approver)** | see note | ✓ | — | — | — | — |
| View improvements | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Propose improvements | ✓ | ✓ | ✓ | ✓ | — | — |
| Approve improvements | ✓ | ✓ | — | — | — | — |
| **Verify an improvement (owner ≠ verifier)** | see note | ✓ | — | — | — | — |
| Bulk Excel import (dry-run + all-or-nothing) | ✓ | ✓ | — | — | — | — |
| View the risk register, heatmap and taxonomy | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Record a risk assessment (inherent / residual / target) | ✓ | ✓ | ✓ | own risk | — | — |
| **Publish a high-scoring assessment (assessor ≠ reviewer)** | see note | ✓ | ✓ | — | — | — |
| View risk appetite statements & the board position | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Author / supersede an appetite statement | ✓ | ✓ | — | — | — | — |
| **Approve an appetite statement (author ≠ approver)** | see note | ✓ | — | — | — | ✓ |
| View treatment plans | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Propose a treatment plan, record progress | ✓ | ✓ | ✓ | own plan | — | — |
| **Approve a treatment plan (owner ≠ approver)** | see note | ✓ | — | — | — | — |
| **Accept a risk (CFH only, expiry mandatory)** | — | ✓ | — | — | — | — |
| **Verify a treatment (owner ≠ verifier)** | see note | ✓ | — | — | — | — |
| View KRI / KPI / KCI indicators and trends | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Define indicators, thresholds and calculations | ✓ | ✓ | ✓ | — | — | — |
| Capture readings | ✓ | ✓ | ✓ | ✓ | — | — |
| Acknowledge / resolve a KRI breach | ✓ | ✓ | ✓ | own metric | — | — |
| Create and remove relationship links | ✓ | ✓ | ✓ | — | — | — |

**Segregation of duties (FR-12.3), enforced in `ExceptionPolicy` + `ExceptionService`
with no admin bypass:** only a Control Function Head may move an exception to
Verified-Closed; the tester whose test raised it can never close it; a control owner
can never close an exception on their own control; owners can reach *Remediated* and
no further. Covered by explicit failing-path tests in
`tests/Feature/SegregationOfDutiesTest.php`.

The Phase 8 gates work the same way and are equally bypass-free: "see note" in the
rows above means a System Administrator has the permission but **still cannot pass
the gate on their own work** — they cannot approve a mapping they created, accept a
filing they recorded, or sign off an impact assessment they wrote. Failing-path
tests: `tests/Feature/FrameworkCoverageTest.php`,
`tests/Feature/ObligationSubmissionTest.php`,
`tests/Feature/RegulatoryChangeWorkflowTest.php`,
`tests/Feature/ObligationEngineTest.php` (non-applicability declarations).

The Phase 9 gates follow the same pattern: a campaign's creator cannot approve
it, a respondent cannot review their own CSA response, a CSA result never
touches a control rating until a Control Function Head applies the proposal, a
document's owner cannot approve it, an entity owner cannot approve their own
decline of a distributed control, and an improvement's owner cannot verify it.
Failing-path tests: `tests/Feature/ControlDistributionTest.php`,
`tests/Feature/CsaEngineTest.php`, `tests/Feature/DocumentWorkflowTest.php`,
`tests/Feature/ImprovementActionTest.php`,
`tests/Feature/SurveyAnonymityTest.php` (anonymity is provable on the raw row
and in the audit trail).

The Phase 10 gates are the same shape and equally bypass-free: a risk assessment
scoring at or above the tenant's review threshold cannot be published by the
person who made it, an appetite statement cannot be approved by its author, a
treatment plan cannot be approved or verified by its owner, and accepting a risk
is a Control Function Head decision that must carry an expiry date. Each has an
explicit failing-path test that puts a System Administrator on the wrong side of
the gate and asserts it still holds: `tests/Feature/RiskAssessmentTest.php`,
`tests/Feature/RiskAppetiteTest.php`, `tests/Feature/TreatmentPlanTest.php`.

## Audit trail

Immutable, append-only `audit_trails` table (updates/deletes throw). Opt any model
in with one trait:

```php
use App\Models\Concerns\Auditable;

class Control extends Model
{
    use Auditable;   // logs created / updated (with before+after) / deleted
}
```

Named domain events are recorded with
`$model->auditAction('approved', $before, $after)` — used for approvals, closures,
reopens, report issuance, and escalations. Every row carries user, IP, user agent,
and before/after JSON. A logging failure never breaks the business operation
(`AuditTrailService` catches and reports).

## Scheduled commands

| Command | Schedule | Purpose |
|---|---|---|
| `secondline:generate-test-instances` | daily 01:00 | Create test instances from each active control's frequency (idempotent) |
| `secondline:refresh-ageing` | daily 01:15 | Recompute `age_days` / `is_overdue`; reopen expired risk acceptances |
| `secondline:expire-compensating-controls` | daily 01:30 | Expire temporary compensating controls; re-flag parent exceptions |
| `secondline:queue-evidence-disposal` | daily 02:00 | Queue expired evidence for dual-approval disposal (legal holds excluded) |
| `secondline:run-escalations` | daily 07:00 | Evaluate escalation matrices, notify by in-app + queued email |
| `secondline:send-owner-digests` | Mondays 07:30 | Open/overdue digest per control owner |
| `atheris:generate-obligation-instances` | daily 02:30 | Materialise the compliance calendar within a rolling 400-day horizon, recompute overdue status and penalty exposure, fire graduated reminders at T-90/-60/-30/-14/-7/-3/-1/0 and daily once overdue (idempotent) |
| `atheris:poll-regulatory-feeds` | daily 06:00 | Poll regulator publication feeds; new items land as `New` regulatory changes, deduplicated on the feed GUID |
| `atheris:remind-document-reviews` | Mondays 08:00 | Notify document owners of governing documents due for review within 30 days |
| `atheris:refresh-risk-posture` | daily 03:00 | Flag overdue treatment plans and milestones, reopen expired risk acceptances, and re-evaluate every active risk against its appetite statement |
| `atheris:evaluate-metrics` | daily 03:30 | Compute calculated metrics from their expressions, re-evaluate threshold bands, open KRI breaches (with a linked exception) and escalate them |

Run on demand:

| Command | Purpose |
|---|---|
| `atheris:install-content-pack {code} [--pack-version=] [--dry-run] [--all] [--list]` | Install a versioned regulatory content pack. Idempotent, checksummed, prints a diff report first, never writes a tenant-owned record |

## Reports & exports

Report layouts are **configurable templates** (`report_templates.sections`), never
hard-coded; three defaults ship (Spot Check Report, Exception Register, Control
Testing Summary). PDF via dompdf, Excel via PhpSpreadsheet:
exceptions/controls/test-instance list exports, spot check report download, a
testing summary, and a **board pack** combining selected reports into one PDF.

## Evidence & NDPA (Phase 5)

Evidence attaches polymorphically to test instances, exceptions, and findings.
Upload is **blocked until the uploader declares whether customer personal data is
present** (with data categories when yes) and shows a data-minimisation prompt.
Files live on a non-public disk, stream only through an authorised, access-logged
download route, and carry a SHA-256 checksum. Retention policies (tenant-set, DPO
decides the values) compute an expiry per item; expired items enter a **dual-approval
disposal queue** — two different Control Function approvers, immutable disposal log.
**Legal hold overrides disposal absolutely**, enforced in the workflow, the daily
job, and the model layer itself.

## Integration layer (Phase 6)

Per-tenant configuration for ThirdLine/NexusRisk with three modes (SecondLine
master / ThirdLine master / bidirectional, conflict rule `last_approved_at` wins).
Outbound publication fires on control approval/retire, test review sign-off, and
exception closure — every attempt logged in `integration_sync_logs` with replay
for failures. Inbound `/api/v1` (hashed per-tenant API keys via `X-Api-Key`,
`Idempotency-Key` on writes, strict tenant scoping): pull controls / test results /
exceptions, push control definitions. Spec: [docs/openapi.yaml](docs/openapi.yaml).

## Platform foundations v2 (Phase 7)

Every Phase 7 module ships behind a **feature flag** (`feature_flags`, seeded
enabled; tenant overrides win — `Admin → Feature Flags`).

- **SSO (SAML 2.0 + OIDC)** — `sso_configurations`, encrypted secrets, JIT
  provisioning with IdP-group→role mapping, allowed email domains, SP metadata
  endpoint, admin test-connection (no session created). **Maker-checker on
  authentication config**: a configuration or change takes effect only after a
  *second* System Administrator approves (`SsoConfigurationPolicy`, no admin
  bypass). Once SSO is active, password login is **break-glass only**
  (`users.is_break_glass`): capped per tenant per day, always audited, every
  use alerts all System Administrators.
- **MFA** — TOTP (`pragmarx/google2fa`) with QR enrolment, hashed single-use
  recovery codes, email OTP backup (SMS is opt-in with a SIM-swap warning).
  Per-role enforcement via `tenants.mfa_enforced_roles` with a grace period,
  then a hard block (`EnforceMfa` middleware). Removing enforcement from a
  role writes a high-severity audit event and notifies all System Administrators.
- **Tenant branding** — `tenant_brandings`: logos, favicon, colours (primary
  must pass a 4.5:1 WCAG contrast check or is rejected), login background,
  PDF report header/footer HTML, email sender name. AEGIS tokens remain the
  defaults; overrides inject as CSS custom properties.
- **Notification dispatcher** — seeded `notification_events` catalogue (R1) +
  per-user `notification_preferences` (channel on/off, digest frequency,
  quiet hours in tenant timezone). `NotificationDispatcher` routes every
  notification; whatsapp/sms/push are registered no-op drivers until Phase 15.
- **Audit log UI** — `Admin → Audit Log`: server-side filters, field-level
  before/after diff viewer, cursor pagination, CSV export behind
  `export audit log` (the export is itself audited). "Audit trail" panels on
  Control / Exception / Test Instance pages via a permission-mirrored endpoint.
- **Localisation & money** — tenant locale/timezone/currency/date-format/
  fiscal-year settings shared to the frontend; `App\Support\Money` value
  object (integer minor units + ISO-4217, floats rejected — R7);
  `exchange_rates` reference table with tenant overrides.
- **Global search** — Cmd/Ctrl-K palette over controls, risks, exceptions,
  test instances, spot checks, findings. MySQL FULLTEXT (LIKE fallback on
  SQLite). Results are tenant- **and** permission-scoped — a user never sees
  a title they cannot open.
- **Saved views** — save FilterBar filter sets per list, default per resource,
  share with colleagues in the same role.
- **PWA + low bandwidth** — installable manifest (tenant-branded), service
  worker with cache-first shell and an offline fallback page, per-user
  low-bandwidth mode (kills animations; later phases honour it for charts and
  images), connection-quality banner.

## Framework & regulatory obligation engine (Phase 8)

Behind four feature flags: `frameworks`, `obligations`, `regulatory-changes`,
`content-packs`.

- **Frameworks & requirement trees** — `frameworks` + self-referencing
  `framework_requirements` (domain → principle → control objective). Shipped
  frameworks are global (`tenant_id NULL`); a tenant may hold its own copy, and
  the installer never touches a tenant-owned row. Explorer shows the tree with
  per-requirement coverage, plus a **requirement × entity coverage heatmap**.
- **"Test once, satisfy many"** — `control_requirement_map` is the tenant's claim
  that a control satisfies a requirement, and it is **maker-checker**: an officer
  maps, a Control Function Head approves, and an unapproved mapping counts for
  nothing in coverage and never reaches a submission. `framework_mappings` draws
  equivalences between requirements across frameworks, so one control's page
  lists every requirement it satisfies, directly and by inheritance.
- **Deterministic mapping suggestions** — cosine similarity over TF-IDF vectors of
  the requirement and control text, returned with the shared terms that produced
  the score. Suggestions are drafts a person judges; Phase 14 adds the AI-assisted
  variant under the same rule.
- **Obligation register** — `regulatory_obligations` carries the due rule,
  applicability, legal reference, and the penalty as **integer minor units plus an
  ISO-4217 code** (R7). Nothing about a regulator lives in PHP.
- **Due-date resolution** — `ObligationService` resolves three rule shapes against
  the entity's fiscal calendar, jurisdiction holidays (`public_holidays`, seeded
  and tenant-overridable) and the tenant timezone:
  `fixed_date` (first occurrence after the period it reports on),
  `relative` (offset from a fiscal or period anchor) and
  `event_relative` (an hour-precise countdown that does **not** roll off a weekend —
  a 72-hour breach notification stays 72 hours).
- **Applicability engine** — an obligation's `applies_to` is matched against the
  entity's regulatory profile (entity type, licence categories, jurisdiction). A
  tenant may declare an obligation inapplicable, but the declaration only
  suppresses anything **after a second person approves it**.
- **Cost of non-compliance** — live penalty exposure per overdue instance for every
  `penalty_basis`: `fixed`, `per_day`, `per_week` (a part-week counts as a whole
  week — the CBN consumer-protection case), `per_instance`, and `percentage`
  (stored in basis points; without a declared base the exposure is **zero, never
  invented**).
- **Compliance calendar** — month/quarter/year views with a filing-density strip,
  filters by regulator, entity, owner and status. An instance only reaches
  *Submitted* with a submission reference **and** at least one evidence item, and
  only a second person can accept it.
- **Regulatory change feed** — manual entry plus RSS ingestion where a regulator
  publishes one, through New → Under Review → Impact Assessed → Actioned with
  maker-checker on Actioned and links to the affected obligations and controls.
- **Content packs** — versioned, checksummed JSON in `database/content-packs/`,
  installed by `ContentPackInstaller`. 45 packs ship: 11 international frameworks,
  20 Nigerian regulator packs, 13 pan-African packs and one cross-framework mapping
  set (30 equivalences). Installs are idempotent, produce a diff report first, and
  only ever write global rows.

**Verification status is enforced, not advisory (R10).** Every framework,
requirement and obligation carries `verification_status`. Only `verified` records
may enter a generated regulatory submission — `ObligationService::submissionEligibleInstances()`
is the single gate, and everything else is badged "Unverified" wherever it appears.
As shipped: **COSO IC 2013, COSO ERM 2017 and the ISO 31000 principles are
`verified`; every Nigerian pack is `unverified`; every pan-African pack is `draft`**,
because none of those dates, thresholds or penalties has yet been confirmed against
the regulator's primary document. The 13 known-unverified research items are
recorded in each pack's `changelog`. Verifying them is the phase-8 research backlog,
not a code change.

## Control library v2, CSA & surveys (Phase 9)

All Phase 9 modules are feature-flagged (`control-distribution`, `csa`, `surveys`,
`attestations`, `documents`, `improvements`, `bulk-import`).

- **Group vs entity libraries** — a control carries `library_level`; distributing a
  distributable, Active group control creates one entity child per entity
  (`parent_control_id`), idempotently, each with its own owner, testing and rating.
  Changing a group control goes through `DistributionService::propagate()`:
  preview → propagate or notify-only, and a field an entity has locally adapted is
  **never silently overwritten**. Declining a distributed control requires a reason
  and Control Function Head approval; a declined control still reports as a
  coverage gap.
- **Distribution overview** — Corporater-style org-tree screen with the stat tiles
  (entities distributed to · tasks completed · outstanding · implementation %),
  per-entity task lists that roll progress up into the entity control's
  `implementation_status`.
- **CSA engine** — campaigns (`csa_campaigns`, one engine for CSA, attestations and
  surveys) with maker-checker approval before opening, questionnaire builder with
  sections, weights, conditional visibility (`condition`) and exception triggers
  (`triggers_exception_on` → an auto-raised `CSA`-sourced exception), weighted /
  maturity scoring, reviewer workflow with variance flagging beyond the campaign
  threshold, and a live response-rate gauge. **CSA never auto-rates**: results
  surface as `proposed_design_rating`, applied only by a Control Function Head
  through `CsaService::approveProposedRating()`.
- **Surveys** — same machinery with `is_anonymous`: an anonymous response stores no
  respondent, no entity, no IP — and its audit-trail rows are unattributed
  (`auditsAnonymously()` in `AuditTrailService`). No role can open the row; only
  aggregates are exposed. Proven in `SurveyAnonymityTest`.
- **Attestations** — the exact text signed is snapshotted verbatim with timestamp,
  IP, method and source version; editing the source later cannot change what was
  signed. Non-attestation escalates through the existing escalation matrix via the
  new `attestation_overdue` trigger.
- **Documents** — governing artefacts, distinct from Evidence: folder tree,
  SHA-256-hashed files on a non-public disk, maker-checker approval
  (owner ≠ approver), publish with supersession chain, review-due scheduling with
  reminders, confidential documents gated by role list at both query and policy
  level, download logging, and global-search integration.
- **Bulk Excel import** — `ImportService` for controls, risks, obligations, org
  units and users: template download with data-validated dropdowns, dry-run
  row-level error report that writes nothing, then an all-or-nothing transactional
  import with a tenant-level audit entry. Imported obligations arrive
  `unverified` (R10).
- **Version control everywhere** — the shared `HasVersions` trait snapshots
  versioned attributes into `object_versions` on create and change; test scripts,
  documents, questionnaires, frameworks and report templates are wired in, with a
  field-level diff endpoint (`/versions/{alias}/{id}/diff`) and side-by-side UI.
- **Improvement database** — actions from any source through
  Proposed → Approved → In Progress → Implemented → Verified with independent
  verification (owner ≠ verifier), surfaced on control pages as "known
  improvements".

## Risk management v2 (Phase 10)

Feature-flagged: `risk-assessments`, `risk-heatmap`, `risk-appetite`,
`risk-treatments`, `metrics`, `linkage`.

- **Taxonomy & scales as data (R1)** — a 12-branch risk taxonomy ships globally
  (`risk_categories`, `tenant_id` NULL); every likelihood level, impact level,
  impact-dimension level, velocity level and rating band lives in
  `risk_assessment_scales` per tenant. The grid size *is* the row count: deleting a
  level turns the 5×5 matrix into a 4×4 one, and the band cut-offs and heatmap
  colours are columns on the band rows. No matrix dimension, label, threshold or
  colour is written in PHP — proven by `RiskHeatmapTest::test_a_custom_four_by_four_scale_needs_no_code_change`.
- **Assessment chain** — `risk_assessments` holds dated inherent / residual / target
  rows with likelihood and impact rationales, per-dimension impact scores
  (financial, regulatory, reputational, operational, customer, people) aggregated by
  a configurable weighting that **keeps the driving dimension visible** and never
  averages a Critical dimension away. Publishing pushes the position onto the risk;
  above the tenant's `review_threshold` it needs a second-line reviewer who is not
  the assessor.
- **Residual is unchanged (R8)** — `ResidualRiskService` still owns the
  control-driven number and the 20%-of-inherent floor.
  `RiskAssessmentService::recomputeResidual()` wraps it, derives the residual grid
  cell so the heatmap has somewhere to plot, and re-checks appetite. Parity with the
  phase 0-6 engine is asserted directly in `RiskAssessmentTest`.
- **Quantitative path** — expected loss, VaR 95/99 and a loss-exceedance curve from
  a 10,000-iteration Monte Carlo over triangular or PERT severity with a Bernoulli
  occurrence draw. Pure PHP (`App\Support\LossSimulator`), cached on an input
  fingerprint, and **deterministic**: a self-contained xorshift32 generator seeded
  per run, so a published VaR is reproducible and the global `mt_rand` state is
  never touched.
- **Heatmaps** — inherent, residual, target and a residual→target movement view,
  filterable by taxonomy, entity, owner, process, framework and appetite breach.
  Bubble size is total financial impact; a cell click drills through to the risks
  behind it. Every cell carries its **band label beside its colour**, the legend
  names each band with its score window, and a table view renders the same numbers
  without colour at all.
- **Risk appetite** — board-approved statements per category and entity with
  tolerance bands and capacity, resolved down the taxonomy so a risk filed under
  *Fraud* is governed by the *Operational* statement. Statements are versioned:
  a change supersedes rather than edits, and the author never approves. Crossing
  tolerance flags the risk and fires the `appetite_breach` escalation immediately,
  not at the next nightly sweep.
- **Treatment plans** — Avoid / Reduce / Transfer / Accept / Exploit with
  milestones, cost and benefit, progress, configurable overdue alerting
  (`treatment_overdue`), and verification by someone other than the owner.
  Acceptance reuses the exception pattern: Control Function Head approval plus a
  mandatory expiry, after which the plan returns to the register.
- **KRI / KPI engine** — definitions, threshold bands (`gt`, `gte`, `lt`, `lte`,
  `between`, `outside` — the last two are exact complements), manual, integration
  and calculated capture, breach detection, sparklines in the list and a full trend
  chart with threshold bands on the metric page. A Red or Critical reading opens a
  breach, escalates it and — per tenant configuration — raises a linked exception.
  Calculated metrics run through `App\Support\ExpressionEvaluator`: a whitelisted
  recursive-descent parser over `+ - * / % ^`, comparisons, parentheses,
  `[metric.CODE]` references and ten named functions. **`eval()` is never called**;
  unsafe input fails to tokenise before evaluation begins.
- **Linkage graph** — `entity_links` is polymorphic by alias across risk, control,
  metric, exception, treatment, obligation, requirement, document, improvement and
  process. A Relationships panel sits on every major record, with a two-hop
  force-relaxed graph view behind a toggle. Adjacency is computed server-side, node
  labels resolve one query per type, and the node cap is **reported** rather than
  silently applied.

New routes (all named, all behind their feature flag and permission):

| Route | Name |
|---|---|
| `GET /risks/heatmap`, `GET /risks/heatmap/cell` | `risks.heatmap`, `risks.heatmap.cell` |
| `POST /risks/{risk}/assessments` (+ `/publish`, `/reject`, `/simulate`) | `risks.assessments.*` |
| `GET /risk-appetite` (+ `store`, `/submit`, `/approve`, `/supersede`) | `appetite.*` |
| `GET /treatments`, `GET /treatments/{treatment}` | `treatments.index`, `treatments.show` |
| `POST /risks/{risk}/treatments`, `/approve`, `/progress`, `/verify`, `/cancel`, `/milestones` | `risks.treatments.store`, `treatments.*` |
| `GET /metrics`, `GET /metrics/{metric}` (+ `store`, `update`, `/values`, `/breaches/{breach}/acknowledge`, `/resolve`) | `metrics.*` |
| `POST /links`, `DELETE /links/{link}`, `GET /links/candidates`, `GET /links/graph/{type}/{id}` | `links.*` |

The `/api/v1` surface is unchanged, so `docs/openapi.yaml` needs no revision this
phase.

## Key business rules (where they live)

- **Maker–checker** on controls, test scripts, ratings, compensating controls — policies + services
- **Auto-exception from every failed check item** — `TestingService::submit()` (FR-3.6)
- **Lifecycle state machines** — `ControlService::TRANSITIONS`, `ExceptionService::TRANSITIONS`
- **Configurable rating matrix** — `rating_matrix_entries` (seeded BRD §7.3 defaults, tenant-overridable), resolved by `RatingMatrixEntry::resolve()` — never hard-coded
- **Residual risk** — `ResidualRiskService`: inherent × weighted control effectiveness + approved compensating controls; floored so no risk falls below 20% of inherent without effective controls
- **Recurrence detection** — same control failing across periods links and flags (FR-5.9)
- **Risk grid, bands and colours** — `risk_assessment_scales` rows, per tenant; resolved by `RiskAssessmentService::bandFor()` / `HeatmapService::axis()` — never hard-coded
- **Second-line review threshold, impact weights, simulation seed** — `tenants.settings['risk']`, defaults in `RiskAssessmentService`
- **KRI breach action (exception / incident / none) and breach levels** — `tenants.settings['risk']`, read by `MetricService::config()`
- **Metric expressions are parsed, never executed** — `App\Support\ExpressionEvaluator`; validated at save time by `MetricRequest`

## Quality gate

```bash
composer test        # 365 feature/unit tests incl. SoD, legal-hold, dual-approval,
                     # due-rule and penalty maths, content-pack idempotency,
                     # API-auth bypass attempts, distribution idempotency,
                     # CSA rating gates, survey anonymity, import rollback,
                     # residual-engine parity and the 20% floor, Monte Carlo
                     # determinism, a custom 4×4 heatmap scale, every threshold
                     # operator, expression-evaluator injection attempts,
                     # KRI-breach → exception linkage and appetite escalation
vendor/bin/pint      # lint
npm run build
```

## Roadmap position

BRD Phases 0–6 are implemented: foundation, control library, testing, exceptions &
escalation, spot checks & dashboard (MVP), reports & exports, evidence retention /
NDPA safeguards, and the ThirdLine/NexusRisk integration layer with OpenAPI spec.

**v2.0 Phase 7 (Platform Foundations v2) is implemented**: SSO (SAML 2.0/OIDC with
maker-checker and break-glass), MFA with role enforcement, tenant branding,
notification preferences + multi-channel dispatcher, audit log UI, localisation +
multi-currency Money, global search, saved views, feature flags, and the PWA shell
with low-bandwidth mode.

**v2.0 Phase 8 (Framework & Regulatory Obligation Engine) is implemented**: the
framework and requirement-tree engine with maker-checker control mapping and
coverage heatmaps, the regulatory obligation register with due-rule resolution,
applicability and live penalty exposure, the compliance calendar with evidence-backed
submission and second-person acceptance, the regulatory change feed, and 45 versioned
content packs covering international, Nigerian and pan-African regulation.

**v2.0 Phase 9 (Control Library v2, CSA & Surveys) is implemented**: group/entity
control libraries with distribution and implementation tracking, the CSA engine
with conditional questionnaires and CFH-gated proposed ratings, anonymous-capable
surveys, attestation campaigns with verbatim text snapshots, document management
with maker-checker and supersession, bulk Excel import with dry-run validation,
the shared `HasVersions` version store with field-level diffs, and the improvement
database.

**v2.0 Phase 10 (Risk Management v2) is implemented**: the risk taxonomy and fully
configurable assessment scales, inherent/residual/target assessment with
multi-dimensional impact and second-line review, a deterministic Monte Carlo
quantitative path with VaR and a loss-exceedance curve, configurable N×N heatmaps
with a movement view and cell drill-through, versioned board-approved risk appetite
with live breach escalation, treatment plans with milestones and independent
verification, the KRI/KPI/KCI engine with configurable threshold bands and safe
calculated expressions, and the universal linkage graph. Next per the v2 master
plan: Phase 11 (Policy, Incident, Complaints & Case Management). Still pending from
v1 backlog:
Word-format report export, webhook subscriptions, NexusRisk risk-register pull,
in-place evidence redaction and data-subject-request tooling, continuous controls
monitoring (v2 Phase 12).
