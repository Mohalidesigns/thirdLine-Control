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
| View the policy library and gap analysis | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Draft and edit a policy | ✓ | ✓ | ✓ | — | — | — |
| **Approve a policy (owner ≠ approver)** | see note | ✓ | — | — | — | — |
| **Publish a policy (owner ≠ publisher)** | see note | ✓ | — | — | — | — |
| Request a policy waiver | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Approve / revoke a waiver (requester ≠ approver)** | see note | ✓ | — | — | — | — |
| View the incident register | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Report an incident (incl. near misses) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Triage, investigate, record loss and actions | ✓ | ✓ | ✓ | — | — | — |
| Record / waive a regulatory notification | ✓ | ✓ | ✓ | — | — | — |
| **Close an incident (reporter ≠ closer, gated on actions + notifications)** | see note | ✓ | — | — | — | — |
| View the complaint register and root-cause analysis | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Log a complaint | ✓ | ✓ | ✓ | ✓ | — | — |
| Acknowledge, assign, investigate, resolve | ✓ | ✓ | ✓ | — | — | — |
| Close / escalate a complaint to the regulator | ✓ | ✓ | — | — | — | — |
| Export the CBN CPD return | ✓ | ✓ | ✓ | — | — | — |
| **View a case** | allowlist only | allowlist only | allowlist only | allowlist only | allowlist only | allowlist only |
| Open a case | ✓ | ✓ | ✓ | — | — | — |
| Investigate, note, manage the allowlist | allowlist only | allowlist only | allowlist only | — | — | — |
| **Conclude a case (reporter ≠ concluder)** | allowlist only | allowlist only | allowlist only | — | — | — |
| Read privileged notes | allowlist + lead/permission | ✓ | — | — | — | — |
| Aggregate case board extract (no case detail) | ✓ | ✓ | — | — | — | ✓ |
| Raise a Speak-Up report | public — no login required | | | | | |
| View data sources and their health | ✓ | ✓ | ✓ | — | — | — |
| Register / configure a data source (credentials write-only) | ✓ | ✓ | — | — | — | — |
| **Approve a data source into production (registrant/owner ≠ approver)** | see note | ✓ | — | — | — | — |
| **Authorise retaining sensitive personal data in clear (DPO act)** | ✓ | ✓ | — | — | — | — |
| View monitoring rules, runs, findings and coverage | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Author and tune monitoring rules | ✓ | ✓ | ✓ | — | — | — |
| **Approve a monitoring rule into production (author/owner ≠ approver)** | see note | ✓ | — | — | — | — |
| Run a rule on demand (reaches a live source) | ✓ | ✓ | ✓ | — | — | — |
| Review / confirm / dismiss a monitoring finding | ✓ | ✓ | ✓ | — | — | — |
| View the SoD conflict matrix and violations | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Maintain the conflict matrix, mitigate and remediate | ✓ | ✓ | ✓ | — | — | — |
| **Accept a live SoD conflict (CFH only, expiry mandatory)** | ✓ | ✓ | — | — | — | — |
| Open a dashboard shared with you | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Compose a private dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Share a dashboard with a role, the tenant or a public link** | ✓ | ✓ | — | — | — | — |
| Design report definitions and their sections | ✓ | ✓ | ✓ | — | — | — |
| **Approve a report definition (author ≠ approver)** | ✓ | ✓ | — | — | — | — |
| Schedule and distribute a report | ✓ | ✓ | ✓ | — | — | — |
| Generate a report on demand, download a run | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View regulatory submission packs | ✓ | ✓ | ✓ | — | — | ✓ |
| Generate / edit a submission pack | ✓ | ✓ | ✓ | — | — | — |
| **Review a submission pack (generator ≠ reviewer)** | see note | ✓ | ✓ | — | — | — |
| **Approve a submission pack (approver ≠ generator ≠ reviewer)** | see note | ✓ | — | — | — | — |
| **Record a pack as filed / acknowledged / rejected** | ✓ | ✓ | — | — | — | — |
| Use the AI assistants (`use ai`) | ✓ | ✓ | ✓ | ✓ | ✓ | Atlas only |
| **Accept an AI draft** (creates the record) | — | per capability | per capability | per capability | — | — |
| AI governance: enable capabilities, models, prompts, budgets (`manage ai`) | ✓ | ✓ | — | — | — | — |
| View / export the AI activity log | ✓ | ✓ | — | own only | — | — |
| My-tasks mobile view, offline outbox, chunked uploads, push subscription | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Approvals / SoD transitions from the offline queue** | never — rejected server-side for every role | | | | | |
| Messaging admin: templates, Meta sync, delivery log, channel costs (`manage messaging`) | ✓ | ✓ | — | — | — | — |
| View the legal-entity register | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Create / edit legal entities | ✓ | ✓ | — | — | — | — |
| **Grant / revoke per-entity access (a grant, not a role, opens an entity)** | ✓ | ✓ | — | — | — | — |
| Group dashboard (granted entities only — no grant, no numbers, any role) | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| View residency declaration, transfer register, attestations | ✓ | ✓ | ✓ | — | — | ✓ |
| Record a cross-border transfer (lawful basis mandatory) | ✓ | ✓ | ✓ | — | — | — |
| **Authorise a transfer (recorder ≠ authoriser)** | see note | ✓ | — | — | — | — |
| Generate & sign a residency attestation (`manage residency`) | ✓ | ✓ | — | — | — | — |
| Record SoA applicability decisions (`manage soa`) | ✓ | ✓ | ✓ | — | — | — |

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

The Phase 11 gates follow the same pattern — a policy's owner cannot approve or
publish it, a waiver's requester cannot approve it, an incident's reporter cannot
close it, an action's owner cannot verify it, and a case's reporter cannot
conclude it. Failing-path tests: `tests/Feature/PolicyLifecycleTest.php`,
`tests/Feature/IncidentManagementTest.php`, `tests/Feature/CaseConfidentialityTest.php`.

The Phase 12 gates are the newest and the ones with the most reach, because a
monitoring rule decides whether a control is failing and a data source holds a
live credential into a bank's core. Neither activates on one person's say-so: a
rule cannot be approved by its author *or* its owner, a source cannot be approved
by whoever registered *or* owns it, and **editing what an active rule tests drops
it straight back to Pending Approval** — a tuning change is a change to the
control test, so it gets looked at again. Accepting a live segregation-of-duties
conflict is a Control Function Head decision carrying a mandatory expiry, and the
acceptance reopens automatically when it lapses. Failing-path tests, including one
that puts a System Administrator on the wrong side of the rule-approval gate:
`tests/Feature/ContinuousMonitoringTest.php`, `tests/Feature/MonitoringPagesTest.php`.

Phase 14 adds a gate of a different kind, because the actor is not a person.
**AI never decides (R4), and that is structural rather than procedural**: the
gateway returns an `AiDraft` whose payload has no getter, and the only way to
obtain it is `accept(User $approver)`, which records the decision and returns an
`AcceptedDraft`. Every domain service that can persist AI output types its
parameter as `AcceptedDraft`, and that class's constructor re-reads the
interaction from the database and refuses to exist unless a human decision is
already recorded against it by the person applying it. There is no code path —
including from inside the AI layer — that turns model output into a record
without a named human in the audit trail.

The existing gates survive underneath it, which is the point. An accepted control
draft lands as **Draft**, unapproved, with the accepter as its creator, so
maker-checker still applies and they cannot then approve their own control.
Accepted triage writes a root cause and a remediation plan and **does not touch
the exception's status** — that lifecycle still ends with a Control Function Head
verifying a fix they did not perform. Framework mappings enter the maker-checker
queue with `approved_by` deliberately null. Evidence review has no `apply()` at
all and cannot set a test result. And every capability requires the same domain
permission its manual equivalent does, so AI never widens anyone's reach.
Failing-path tests, including attempts to apply an undecided draft, apply one
twice, apply someone else's, and have an advisory capability change a record:
`tests/Feature/AiGovernanceTest.php`.

The Phase 13 gate is the one a regulator would ask about first. A submission pack
is generated by one person, reviewed by a second and approved by a third, and the
three are checked against each other in `SubmissionPackService` as well as in
`SubmissionPackPolicy` — **a System Administrator who generated a pack still cannot
review or approve it**, which `SubmissionPackTest` asserts directly. A report
*definition* carries the same shape one level up: its author cannot approve it, and
changing the sections, formats or classification of an approved definition
withdraws that approval and bumps the version, because a layout signed off with
three sections and now carrying five has not been signed off. Failing-path tests:
`tests/Feature/SubmissionPackTest.php`, `tests/Feature/ReportDesignerTest.php`.

**Case access is the one deliberate exception to admin reach.** `cases` is
allowlist-only: `InvestigationCase` carries an `allowlist` global scope with no
role-based escape hatch, and `InvestigationCasePolicy` starts every method from
the same membership test. A System Administrator holding *every* permission sees
zero rows on the index and a **403** on a case they are not named on — asserted
directly in `CaseConfidentialityTest`. This is required for whistleblowing
integrity, not an oversight. Access is granted only by someone already on the
case, and both the grant and every file open are written to the audit trail.

An allowlist is never allowed to end up **empty**, though — that is not maximal
confidentiality, it is a report nobody can action. A case that names no lead and
no members (a public Speak-Up report) falls back to the tenant's intake roles
(`settings.cases.intake_allowlist_roles`, default `Control Function Head`), then
to anyone holding `investigate cases`; an installation with neither still saves
the disclosure but logs the misconfiguration at critical. The allowlist is
notified on intake with the case reference only — a title can name a subject, and
email is a less controlled channel than the case file.

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
| `atheris:refresh-governance-clocks` | **hourly** | Refresh complaint SLA state and live penalty exposure, recompute every open incident's notification windows from the obligation records, expire policy waivers past their end date, and alert on closing windows. Hourly rather than nightly: a 24-hour acknowledgement clock and a 72-hour breach notification cannot be managed by a job that runs once a day |
| `atheris:sync-data-sources` | daily 04:00 | Extract every active dataset from every approved, unpaused data source into a snapshot, applying the dataset's PII treatment at ingestion. Honours the per-source rate limit and trips the circuit breaker after N consecutive failures |
| `atheris:run-monitoring-rules` | daily 04:30 | Evaluate every due monitoring rule against the night's snapshots, raise findings and exceptions, materialise the linked control's test instance, and expire lapsed SoD acceptances. Runs `--no-capture` on the schedule so it reads what the 04:00 sync produced |
| `atheris:purge-snapshots` | daily 05:30 | Delete snapshot data past its dataset's retention period and mark the record Purged. Anything under legal hold is skipped and the count is reported, because silence would read as "nothing was due" |
| `ai:reindex` | daily 05:45 | Rebuild the AI knowledge index — stale chunks only unless `--all`. Backstop for a queue outage; the queued listener handles the normal path |
| `ai:prune` | Sundays 03:45 | Clear verbatim model output past its retention window. The interaction record itself is never deleted (R3) |
| `reports:run-scheduled` | **every 15 min** | Generate and distribute every report schedule that has fallen due. Every fifteen minutes rather than nightly because each schedule carries its own cron *and its own timezone*: a board pack due 06:45 in Lagos and a group return due 06:45 in London are different moments, and a once-a-night sweep files both late. Runs as the schedule's creator, so a scheduled report never sees more than the person who scheduled it |
| `atheris:backup` | daily 01:30 | Residency-guarded database dump to the backup disk (`RESIDENCY_BACKUP_DISK`), retaining 14 — a backup disk declared outside the tenant's country refuses to accept a byte (16.2). Restore drill: `scripts/restore-drill.sh` monthly (docs/runbooks/disaster-recovery.md) |

Run on demand:

| Command | Purpose |
|---|---|
| `atheris:install-content-pack {code} [--pack-version=] [--dry-run] [--all] [--list]` | Install a versioned regulatory content pack. Idempotent, checksummed, prints a diff report first, never writes a tenant-owned record |
| `atheris:sync-data-sources [--tenant=] [--source=]` | Extract one source, or all of them, outside the schedule |
| `atheris:run-monitoring-rules [--rule=] [--no-capture]` | Run one rule, due or not. `--no-capture` re-evaluates the stored snapshot, which is what testing a rule change wants: same data, corrected rule |
| `atheris:purge-snapshots [--tenant=] [--dry-run]` | Report or enforce snapshot retention |
| `reports:run-scheduled [--tenant=] [--schedule=]` | Run one schedule now, due or not — the same path the scheduler takes |
| `tenant:provision {name} [--admin-email=] [--residency=NG] [--currency=NGN] [--entity-type=bank] [--pack=*]` | Stand up a new tenant end to end: reference data, roles, the tenant with its residency declaration, a head-office unit, the root legal entity, the first administrator (one-time password printed once) and any chosen content packs. The only context allowed to set a residency declaration (16.2) |
| `atheris:backup [--disk=] [--keep=14]` | Take a residency-guarded backup now |

## Reports & exports

Report layouts are **configurable templates** (`report_templates.sections`), never
hard-coded; three defaults ship (Spot Check Report, Exception Register, Control
Testing Summary). PDF via dompdf, Excel via PhpSpreadsheet:
exceptions/controls/test-instance list exports, spot check report download, a
testing summary, and a **board pack** combining selected reports into one PDF.

These Phase 4 paths are unchanged and still work. Phase 13 adds the *designer*
alongside them — `report_definitions` with sections, parameters, four output
engines, approval and scheduled distribution. See
[Dashboards, analytics & reporting v2](#dashboards-analytics--reporting-v2-phase-13).

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

## Policy, incident, complaints & case management (Phase 11)

Feature-flagged: `policies`, `incidents`, `complaints`, `cases`, `whistleblowing`.

- **Policy management** — policies, procedures, standards, guidelines, charters and
  codes through Draft → Under Review → Pending Approval → Approved → Published →
  Under Revision → Superseded/Withdrawn, structured in `policy_sections` that each
  name the controls and framework requirements they govern. Publication supersedes
  the predecessor, sets the next review date from the policy's own frequency, and —
  where the policy demands it — **opens the attestation campaign automatically**,
  reusing the Phase 9 campaign machinery (the policy owner creates it, the publisher
  approves it, so maker-checker is satisfied by the same separation that let the
  policy be published). **Policy gap analysis** inverts Phase 8's coverage view:
  framework requirements no *published policy* claims to govern. Waivers
  (`policy_exceptions`) are time-boxed, never approved by the requester, and expire
  on the hourly sweep.
- **Incident management** — intake, triage, investigation, containment, corrective
  and preventive actions, a material-flagged timeline, root cause and lessons
  learned. Loss is captured as gross / recovery / **derived** net in integer minor
  units plus an ISO-4217 code (R7), tagged with the Basel level-1 event type so
  operational-risk capital work has its taxonomy at source. Naming a failed control
  flags it for re-testing (materialising this period's test instance immediately)
  and raises an exception against it with `source_type = 'Incident'`.
  **The closure gate is real**: an incident cannot close with an open mandatory
  action or an outstanding regulatory notification, and it reports both at once.
- **Regulatory notification engine (R1)** — `NotificationObligationResolver` maps an
  incident type to the *event keys* the seeded obligation records are registered
  against (`breach_detected`, `cyber_incident_detected`, `suspicion_formed` …),
  then resolves each due time through `ObligationService::resolveDueDate` in the
  entity's timezone. **No window is written in PHP**: NDPC's 72 hours is
  `due_rule.offset_hours` on the NDPA-GAID pack, and editing that record moves every
  open countdown — asserted directly in `IncidentManagementTest`. Live countdowns
  render on the incident page beside the obligation's legal reference and its R10
  verification badge. Near misses are first-class and excluded from loss aggregates.
- **Complaints (CBN Consumer Protection)** — omni-channel intake with an
  automatically issued customer-facing tracking ID, a **24-hour acknowledgement
  clock read from the CBN-CPF obligation record**, and a resolution clock. The
  acknowledgement timestamp is **server-side only** — `ComplaintService::acknowledge()`
  takes no time argument at all, and `ComplaintRequest` strips the field from the
  payload, so a crafted request cannot pre-stop a regulatory clock.
  **Live penalty exposure** is computed from the obligation records' penalty fields:
  the per-instance acknowledgement figure when that window is missed, plus one unit
  of the per-week figure for every *started* week past the resolution deadline
  (part weeks round up, counted midnight-to-midnight so a sub-second overshoot
  cannot jump a complaint into its second week). Resolution requires a root cause,
  which feeds a **complaints-by-root-cause analysis** that links back to the failing
  control, plus a CBN CPD returns export.
- **Cases, investigations and whistleblowing** — allowlist-only confidentiality with
  no admin bypass (see the RBAC section above), genuine anonymity (an anonymous case
  stores no reporter id, and `auditsAnonymously()` keeps user, IP and agent out of
  the audit trail while still recording that the event happened), a **one-way
  reporter token** (only its SHA-256 hash is stored, so a returning reporter can be
  verified but never named), investigation plan, privileged notes, substantiation
  outcome, referral, and an **aggregate-only board extract** carrying counts and
  outcomes but no titles, subjects or notes. A public `/speak-up` intake needs no
  login — requiring one to report wrongdoing defeats the control — and an
  anonymising bridge (`openFromAnonymisingBridge`) strips the originating identifier
  before persistence for channels like WhatsApp, recording that it did so.
- **Cross-module linkage (11.5)** — `policy`, `incident`, `complaint` and `case` are
  registered node types on the Phase 10 `entity_links` graph, so
  incident ↔ control ↔ risk ↔ KRI ↔ policy ↔ obligation ↔ complaint ↔ case is one
  navigable graph. A case node resolves only for someone on its allowlist; to
  anyone else it renders as an unavailable node with no route.

Assumptions worth naming: the CBN-CPF pack carries no *per-complaint* resolution
window (its resolution obligation is a weekly calendar duty on the institution), so
the window is a tenant setting — `settings.complaints.resolution_days`, default 14 —
while the penalty amount and basis still come from the obligation record. The
incident-type → obligation-event map is likewise a default that
`settings.incidents.event_map` overrides, following the `MetricService` precedent.

New routes (all named, all behind their feature flag and permission):

| Route | Name |
|---|---|
| `GET /policies`, `/create`, `/{policy}` (+ `store`, `update`) | `policies.*` |
| `POST /policies/{policy}/submit`, `/approve`, `/reject`, `/publish`, `/revise`, `/withdraw` | `policies.*` |
| `GET /policies/gaps` | `policies.gaps` |
| `GET /policy-exceptions`, `POST /policies/{policy}/exceptions`, `/{exception}/decide`, `/revoke` | `policy-exceptions.*` |
| `GET /incidents`, `/create`, `/{incident}` (+ `store`, `update`) | `incidents.*` |
| `POST /incidents/{incident}/triage`, `/progress`, `/loss`, `/close`, `/reopen`, `/controls` | `incidents.*` |
| `POST /incidents/{incident}/actions`, `PUT .../actions/{action}` | `incidents.actions.*` |
| `POST /incidents/{incident}/notifications/refresh`, `/{notification}`, `/{notification}/waive` | `incidents.notifications.*` |
| `GET /complaints`, `/{complaint}` (+ `store`, `update`) | `complaints.*` |
| `POST /complaints/{complaint}/acknowledge`, `/assign`, `/progress`, `/resolve`, `/close`, `/reopen`, `/escalate`, `/incident` | `complaints.*` |
| `GET /complaints/analysis`, `GET /complaints/cpd-return.xlsx` | `complaints.analysis`, `complaints.cpd-return` |
| `GET /cases`, `/{case}` (+ `store`), `/board-extract` | `cases.*` |
| `POST /cases/{case}/assess`, `/investigate`, `/conclude`, `/close`, `/notes`, `/access` · `DELETE /access` | `cases.*` |
| `GET|POST /speak-up`, `/speak-up/submitted`, `/speak-up/status`, `/speak-up/reply` | `whistleblowing.*` (public, throttled) |

The `/api/v1` surface is unchanged again this phase, so `docs/openapi.yaml` needs
no revision.

## Continuous controls monitoring & connectors (Phase 12)

Feature-flagged: `data-sources`, `continuous-monitoring`, `sod-analysis`.

This is the phase that moves control testing from periodic-manual to
continuous-automated. The organising decision is that **it does not build a
parallel universe**: a monitoring rule linked to a control produces a real
`TestInstance`, so an automated result flows into the Phase 3 review, the Phase 7
effectiveness rating and the residual-risk engine exactly as a person's test does.

- **Connector framework** — one abstract `Connector` (`authenticate`,
  `testConnection`, `listDatasets`, `extract`, plus a capability descriptor), one
  class per vendor under `app/Integrations/`, resolved by `ConnectorRegistry` from
  the source's `vendor_key` with a fall-back to the generic connector for its
  transport. Credentials and connection settings are encrypted at rest, hidden
  from serialisation, redacted out of the audit trail, and scrubbed out of any
  third-party error message before it is logged — a PDO exception happily prints
  the DSN it just failed to open. A **circuit breaker** pauses a source after its
  own `failure_threshold` consecutive failures, marks it Failed, notifies the
  owner and applies exponential backoff; resuming is a deliberate, audited human
  act. Rate limiting and timeouts are per source, and every extraction writes to
  the existing `integration_sync_logs` table rather than a parallel one.
- **Connectors** — Priority 1, exercised and marked verified: generic REST/JSON
  (configurable auth, pagination and a dot-path to the row array), generic
  read-only SQL (MySQL / PostgreSQL / SQL Server / Oracle), SFTP or file-drop
  CSV and fixed-width, Microsoft 365 / Entra ID via Graph (users, groups,
  privileged roles, MFA registration, sign-in logs), and manual workbook upload.
  Priority 2, **mapped from vendor documentation but never exercised against a
  live environment**: Finacle, Oracle FLEXCUBE, Temenos T24, Appzone BankOne,
  NIBSS (NIP / BVN watch-list / GSI / NQR), SAP OData, Dynamics 365 Dataverse,
  Sage 300/X3 and the FIRS Merchant Buyer Solution. Every one of them ships with
  `verified = false`, a field-mapping template, and an explicit list of what it
  does *not* cover — and the source page says so in a banner rather than hiding
  it. Confirm the mapping against the institution's own instance before approving
  a rule that depends on it.
- **Rule engine** — eleven types, each a pure function of (rows, definition) with
  its own JSON schema published to the rule builder from the same constant that
  validates it (R1): `threshold`, `reconciliation`, `duplicate`, `gap`,
  `sod_conflict`, `exception_list`, `completeness`, `timeliness`, `trend`,
  `pattern` (Benford, round-number bias, off-hours activity, same-day
  create-and-approve) and `custom_sql`. Sampling is full population, random,
  stratified with a floor of one per stratum, or monetary-unit — always driven by
  a **seed recorded on the run**, because "which forty items did you test in
  March, and why those?" has to have an answer.
- **custom_sql is sandboxed three ways.** It never touches the application
  database: `RuleEngineService` builds a throwaway in-memory SQLite from the
  snapshot rows and runs the statement against that. `App\Support\SqlGuard`
  tokenises the statement and *whitelists* — allowed keywords, allowed functions,
  declared tables, declared named parameters — rather than denylisting words a
  comment can break up. And the sandbox carries a statement timeout and a row cap.
  `SqlGuardTest` covers 24 rejection cases including stacked statements, comment
  obfuscation, `INTO OUTFILE`, `sqlite_master`, cross-database references and
  `load_file()`.
- **Continuous testing** — a failing run raises **one** exception summarising the
  failures through the existing `ExceptionService`, not one per row: a rule that
  finds four thousand duplicate mandates must not bury the register. The generated
  test instance stops at `Submitted`, never `Reviewed` — a machine may gather
  evidence, it may not sign off (R4). Confirming an individual finding raises its
  own exception; dismissing one as a false positive feeds the rule's
  false-positive rate, which is surfaced on the rule page with a tuning prompt
  past 30%.
- **Segregation of duties** — note this is the *client's* SoD in the systems they
  run, distinct from R2, which is SecondLine's own. The toxic-combination matrix
  is seeded data in each system's own vocabulary (a Finacle menu id, an Entra
  role, an SAP transaction code), so adding a pair is configuration. Re-detecting
  a known subject refreshes it rather than reopening it. Accepting a conflict
  needs the `accept sod-violations` permission and a future expiry date, and the
  acceptance reopens on the daily sweep when it lapses.
- **Data protection (12.7)** — every dataset declares a `pii_classification` and
  the fields that carry personal data; a classification with no named fields is
  rejected at authoring time, because it tells the platform nothing to protect.
  `sensitive_personal` fields are replaced with a **keyed HMAC at ingestion** —
  stable, so a subject still joins across snapshots, not reversible — unless the
  DPO has recorded an explicit authorisation on the dataset. Findings mask
  personal fields on screen *even where retention was authorised*: authorising
  storage is not authorising a control officer's screen. Every snapshot path
  begins with the tenant's declared residency country, so which data plane a file
  sits in is readable from the path and asserted in `ConnectorFrameworkTest`.
  Snapshots inherit the Phase 5 retention and legal-hold machinery, and legal hold
  is enforced twice — the purge scope excludes it and `DataSnapshot::booted()`
  throws if anything gets that far.

Assumptions worth naming: `custom_sql` runs against a copy of the snapshot rather
than the source, so it cannot express a query that needs the source's own indexes
or a table the dataset does not extract — that is a deliberate trade of power for
containment. The Priority 2 connectors model T24 as REST enquiries and SAP as
OData because OFS and RFC both need transport this platform will not ship; where a
client only exposes those, they front them with a gateway. And the FIRS mapping is
a working model rather than a verified contract, so nothing built on it should
reach a regulatory output before a human checks it against the taxpayer's own
onboarding pack (R10 in spirit).

New routes (all named, all behind their feature flag and permission):

| Route | Name |
|---|---|
| `GET /monitoring` | `monitoring.dashboard` |
| `GET /monitoring/rules`, `/{rule}` (+ `store`, `update`) | `monitoring-rules.*` |
| `POST /monitoring/rules/{rule}/submit`, `/approve`, `/reject`, `/pause`, `/retire`, `/run` | `monitoring-rules.*` |
| `GET /monitoring/runs`, `/monitoring/runs/{run}` | `monitoring-runs.*` |
| `GET /monitoring/findings`, `POST /{finding}/review`, `POST /bulk-review` | `monitoring-findings.*` |
| `GET /sod/conflicts` (+ `store`, `update`, `destroy`) | `sod.conflicts.*` |
| `GET /sod/violations`, `POST /{violation}/mitigate`, `/accept`, `/remediate`, `/false-positive`, `/escalate` | `sod.violations.*` |
| `GET /admin/data-sources`, `/{data_source}` (+ `store`, `update`) | `admin.data-sources.*` |
| `POST /admin/data-sources/{data_source}/approve`, `/test`, `/resume` | `admin.data-sources.*` |
| `POST|PUT /admin/data-sources/{data_source}/datasets[/{dataset}]`, `/capture`, `/authorise-retention` | `admin.data-sources.datasets.*` |

The `/api/v1` surface is unchanged once more — Phase 12 adds no endpoint, and the
two new `control_exceptions.source_type` values (`Monitoring`, `SoD`) are not part
of the published exception payload — so `docs/openapi.yaml` needs no revision.

## Dashboards, analytics & reporting v2 (Phase 13)

Behind three feature flags: `dashboard-builder`, `report-designer`,
`submission-packs`.

### Configurable dashboards

`dashboards` + `dashboard_widgets` hold the arrangement as data. A widget stores a
**data source key, never a query**: `App\Dashboards\WidgetRegistry` is an allowlist
of 44 registered datasets (`App\Dashboards\Sources\*`), each declaring its own
permission and resolving inside the tenant scope. There is no path from a saved
dashboard to an arbitrary read of the database, and an unknown key resolves to an
error rather than to data.

Resolution is fail-closed three ways: an unknown key returns an error state, a key
the viewer lacks permission for returns a **denial** rather than a filtered view,
and a resolver that throws returns an error state instead of taking the whole
dashboard down. A tile may carry `permission_required` to restrict itself
*further* than its source — never wider.

Nine dashboards ship (`SystemDashboardSeeder`), read-only and duplicated to edit:
Executive & Board, Control Function Head, Control Officer, Control Owner,
Compliance Officer, Risk Officer, Internal Audit & Third Line, and **Design
Effectiveness / Operating Effectiveness** as an explicit pair — a control can be
well designed and still fail in operation, and a board that sees only one number
never learns which. A user lands on their role's default; a tenant with none still
gets the Phase 4 fixed dashboard rather than an empty screen.

**Every number is clickable.** A drill target is emitted by the same code that
produced the aggregate, carrying the exact filters it counted over, and
`DashboardBuilderTest` follows each one through to the filtered list and compares
totals — so a drill-down cannot drift from its own number. The **org-tree rollup**
widget aggregates any measure up the `OrganisationUnit` hierarchy with per-node
own/rolled-up values, expandable branch by branch.

**Charts are an in-house SVG layer** (`resources/js/Components/Charts/`), not a
charting library — 25 KB raw / 7 KB gzipped as its own lazily-loaded chunk, against
roughly 100 KB gzipped for Recharts. On a mid-range Android over 4G that difference
is the whole performance budget (R6), and hitting the mark spec exactly (4px
rounded data-ends square at the baseline, a 2px surface gap between touching fills,
hairline solid grids, ≤24px bars) needs custom shapes in any library anyway. Tiles
below the fold render on intersection; low-bandwidth mode renders eagerly and
without animation.

The palette lives in `resources/css/app.css` as `--chart-*` tokens, mirrored in
`config/charts.php` for dompdf, PhpWord and the PowerPoint writer. It was validated
for colour-vision separation against the AEGIS card surface: worst adjacent pair
ΔE 9.1 under protanopia, 19.6 unsimulated. Three slots sit below 3:1 contrast on
white, which is why **every chart ships a table view** and direct labels — that
relief is a requirement, not a nicety. Status tones (good → critical) are reserved,
never used for series identity, and never carry meaning without their label.

Analytics (13.5) are **deterministic, not AI**: controls most likely to fail next
period, recurrence-prone controls, and obligations at risk of being missed are each
an arithmetic function of recorded history, with the arithmetic printed on the
tile. Nothing here is a model output, so nothing here needs R4's approval gate.

### Report designer

`report_definitions` extends `report_templates` rather than replacing it. A
definition is an ordered section list — cover, contents, narrative, table, chart,
KPI row, page break, appendix, signature block — parameterised by period, entity
and framework. Narrative text takes `{{ period }}`, `{{ entity }}`, `{{ tenant }}`,
`{{ generated_at }}` and `{{ metric:source_key }}` interpolation, so a paragraph
cannot quote a figure the rest of the document contradicts.

Section data resolves through the same `WidgetRegistry`, which means **a report can
never read something a dashboard could not** — one permission model, two surfaces.
A section the requester is not entitled to renders as "Withheld" rather than
disappearing, so a reader knows it exists.

Six output engines from one resolved document: **PDF** (dompdf), **Excel**
(PhpSpreadsheet), **Word** (PhpWord, with native Word charts), **PowerPoint**,
HTML and CSV. Six shipped definitions: board pack, management control report,
control testing summary, exception register, risk profile, regulatory compliance
position.

> **The PowerPoint writer is hand-rolled** (`App\Reports\Pptx\PresentationWriter`),
> against the phase brief's suggestion of `phpoffice/phppresentation`. That library
> pins `phpoffice/phpspreadsheet` to ^4 or below, and **every phpspreadsheet release
> below 5 currently carries published security advisories** — composer refuses the
> install outright. Taking it would have meant shipping a knowingly vulnerable
> dependency into a control-assurance platform *and* downgrading the spreadsheet
> engine the Phase 4 exports already depend on. The writer emits the twelve
> PresentationML parts a valid deck needs; `ReportDesignerTest` renders every
> shipped report through it and the package structure is asserted part by part.

Every generation is a `report_runs` row with its parameters, page count, SHA-256
checksum, expiring download token and distribution record. **Distribution respects
classification**: a Board-classified pack is withheld from recipients who could not
already open board material, and the skip is recorded on the run rather than being
silent.

### Regulatory submission packs

Six generators (`App\Submissions\Generators\*`), each pulling live data,
validating completeness and refusing to put unverified regulatory text into a filed
document:

| Pack | What it answers from live data |
|---|---|
| **FRC/CG/001** — NCCG corporate governance return | Sections A–E, with the apply-and-explain walk over the 28 seeded principles, pre-answered "Yes" only where an approved control mapping evidences the principle. Four signature blocks |
| **NDPC/CAR** — GAID 2025 Art. 10 compliance audit return | Tier determination, DPO independence, RoPA from the connector registry, DPIA register, breach register with **72-hour compliance computed per breach**, cross-border and processor registers, training, fee band |
| **FRC/ICFR** — ICFR management report | COSO 2013 framework statement, top-down scope, key-control testing results, the three-tier deficiency taxonomy with aggregation, and the conclusion |
| **CBN/IA-ASSESSMENT** — internal audit external assessment | Audit universe, plan against execution, issue closure rates, QAIP, independence, the assessor's report |
| **NDIC/DPAS** — management-factor pack | Return timeliness, examiner-recommendation closure, control effectiveness, risk framework status, and the computed basis-point premium impact in money |
| **SEC/CG** — corporate governance report + returns tracker | The SEC principles walk, plus every quarterly and annual return with whether it was filed on time — generated, never typed |

Cross-cutting rules, all tested:

- **"N/A" is prohibited on FRC/CG/001.** A principle is applied or it is not, and
  if it is not the company explains why. An unanswered *or* N/A principle is a
  blocking error, not a warning.
- **Unverified content is excluded and listed** (R10). Every Nigerian content pack
  ships `unverified`, so a governance return generated today excludes all 28
  principles, names each one in `unverified_items` with the reason, and prints them
  as an appendix in the document. The seeded demo deliberately shows this refusal
  rather than faking verification — the refusal *is* the differentiator.
- **The regulator's numbers are data.** The NDPC late-filing rate comes from the
  seeded obligation and is applied only when that record is verified; the NDIC
  add-on schedule comes from tenant settings and is left uncomputed, and said to be
  uncomputed, when it is absent. Neither is a constant in a PHP class (R1).
- **ICFR aggregation escalates and then stops.** Two significant deficiencies in one
  area aggregate to a material weakness — and the pack flags the area for
  *management review* rather than restating the conclusion as fact, because whether
  a cluster rises to a material weakness is a judgement someone signs. Concluding
  "Effective" with a material weakness on the register is a blocking contradiction.
- **Three people, checked against each other.** Generated → reviewed → approved →
  filed, with no administrator bypass at any step.
- **Completeness gates filing.** Below the configured floor (default 95%,
  `SUBMISSION_COMPLETENESS_THRESHOLD`), Submit is refused with the arithmetic
  stated. A blocking validation error refuses it at any score.
- **An approved pack is immutable.** Approval stamps `locked_at` and a SHA-256
  checksum over the answered content — the numbers approved are the numbers filed,
  whichever format the regulator asked for. A rejected pack is never edited back
  into shape: the filed version stays exactly as filed and a new version supersedes
  it.
- Filing a pack marks its linked obligation instance Submitted, so the compliance
  calendar stops chasing a return that has already gone in.

### Routes

`/dashboards`, `/dashboards/{slug}`, `/dashboards/{slug}/edit`, `/dashboards/new`;
`/report-library`, `/report-library/{definition}`, `/report-designer/{definition}`,
`/report-schedules`, `/report-runs`, `/report-runs/{run}/download`;
`/submissions`, `/submissions/{pack}`, `/submissions/{pack}/document`.

### Operational note

Rendering a full board pack through dompdf is memory hungry — the test suite runs
at a 512 MB limit for exactly this reason. Size the worker that runs
`reports:run-scheduled` accordingly.

## AI layer (Phase 14)

A native, governed, auditable AI layer on the Anthropic API. The design premise
is not "add a chatbot" — it is that scarce second-line expertise is the binding
constraint in most of these institutions, and a model that drafts is worth a lot
while a model that decides is a liability. Everything below follows from that.

### The gateway is the only way through

`App\Services\Ai\AiGateway::execute()` runs one pipeline, and there is no second
path to the provider:

```
capability enabled → user authorised → budget → rate limit → retrieval
  → redaction → prompt render → Anthropic call → parse & validate
  → confidence → citations → interaction recorded → AiDraft returned
```

Routing around it would route around redaction, budget and audit at the same
time, which is why capability classes own *what to ask* and nothing about
*how to call*.

### AI never decides (R4), structurally

`AiDraft` holds its payload privately and exposes no getter. The only way to
obtain it is `accept(User $approver)`, which records the decision, writes an
audit event and returns an `AcceptedDraft`. Every domain service that can
persist AI output types its parameter as `AcceptedDraft` — and that constructor
re-reads the interaction from the database and throws unless a human decision is
already recorded against it by the person applying it. A `Pending` draft cannot
be made into an `AcceptedDraft` by any caller, including code inside the AI
layer. See the RBAC section for what survives underneath it.

### The nine capabilities

| Key | What it drafts | Accepting creates | Model tier |
|---|---|---|:-:|
| `control.draft` | Control + attributes + evidence + test script | Control (**Draft**, unapproved) + draft test script | default |
| `regulatory.parse` | Circular → change + proposed obligations | Regulatory change (**New**) + obligations, every one `unverified` and inactive (R10) | reasoning |
| `risk.intelligence` | Risk statements from incidents, complaints, recurring exceptions | Risks (**Draft**, deliberately unscored) | default |
| `evidence.review` | Whether evidence supports an assertion | **nothing** — advisory, has no `apply()` | default |
| `exception.triage` | Root cause, recurrence risk, remediation plan | Root cause + plan on the exception; **never the status** | default |
| `narrative.generate` | Management commentary with citations | **nothing** — the author pastes what they want | default |
| `framework.map` | Requirement → control mappings with rationale | Mappings with `approved_by` null — the maker-checker queue | reasoning |
| `vendor.screen` | Vendor risk profile + due-diligence questions | **nothing** — an adverse finding about a real company is a person's call | default |
| `atlas.chat` | Grounded answers over the tenant's own records | **nothing** | default |

Model identifiers are **data, not constants** (R1): `ai_configurations.model` per
tenant, then the prompt's `model_hint`, then the capability's tier default from
`config/services.php`. Verified against Anthropic's current model documentation
at build time — the prompt pack's draft named `claude-*-4-6` identifiers; the
shipped defaults are Claude Sonnet 5, Claude Opus 5 and Haiku 4.5.

### Retrieval is permission-filtered before the model sees anything

`ai_knowledge_chunks.permission_key` carries the permission its source record
requires, and `RetrievalService` filters on it **in SQL, in the candidate
query** — not after ranking, not before prompt assembly. A record the user
cannot open is never a row the ranker sees. Two colleagues asking Atlas the same
question correctly get different answers.

Ranking is hybrid: FULLTEXT keyword recall (LIKE on SQLite) fused with vector
similarity by reciprocal rank fusion, then a relevance floor. When nothing
clears the floor the result is empty, and an empty result is what makes
*"I don't have enough information in your data to answer that"* an honest
answer rather than a fallback.

**Embeddings are computed locally.** Anthropic publishes no embedding endpoint,
and shipping a tenant's control descriptions and incident narratives to a
third-party embedding provider in bulk would breach R5 for the sake of a ranking
signal. The default driver is a deterministic hashed bag-of-terms, cosine-scored
in PHP over a keyword-prefiltered candidate set — exactly as Part C §C.7 permits.
It is lexical, not semantic: it will not connect "dual authorisation" to
"four-eyes principle" the way a trained model would. Swapping in an in-country
embedding model later is a new driver plus a re-index.

**Never indexed:** investigation cases (not in the source map at all),
confidential documents (gated per-document on an access-role list a single
`permission_key` cannot express), and draft or withdrawn policies.

### Redaction cannot be skipped

`RedactionService` is a mandatory pipeline stage, deterministic and regex-driven
— never model-based. Emails, Nigerian and international phone numbers, NUBAN
accounts, BVN/NIN, Luhn-valid card numbers, and people's names from the tenant's
own user table. Person placeholders are numbered and stable within a request, so
`[PERSON_1] approved [PERSON_2]'s posting` still reads as two people; a single
token would have invented a segregation-of-duties finding.

The gateway then **re-checks the fully assembled payload at the point of egress**
and abandons the call if anything still matches. Only person placeholders
rehydrate for display — putting an account number back would land it on a screen
and in a PDF export, which is what R5 exists to prevent.

`tests/Feature/AiGatewayTest.php` mocks the HTTP client and asserts the outbound
body contains none of it.

### Cost is bounded

Per-tenant monthly token and cost ceilings in `ai_budgets`, optional
per-capability token caps, and per-user / per-capability / per-tenant rate
limits. All checked **before** context assembly, so a tenant at its limit spends
nothing at all. `hard_stop` refuses the call rather than warning; a budget that
only warns is not a budget. Money is integer minor units plus an ISO-4217 code
(R7). Tokens spent on a call that ultimately failed schema validation are still
charged — otherwise a badly-tuned prompt loops for free.

### Everything is audited

`ai_interactions` records every call: prompt version, model, redacted input and
its hash, retrieved record **ids only** (never content), raw and parsed output,
confidence, citations, tokens, cost, latency, the reviewing human and their
decision. Refusals are recorded too — a budget stop, a rate limit and a schema
rejection are separate statuses, because "the model was never asked" and "the
model was asked and we ignored it" are different facts a review needs to tell
apart.

### Governance surface

`Admin → AI` (`manage ai`, separate from `manage settings`): capability
enable/disable, model per capability, prompt version history with field-level
diffs, budget and usage trend, index health, and the **acceptance rate** —
accepted / edited / rejected per capability, with the edit rate shown alongside.
That is the honest quality metric; confidence is only the model's opinion of
itself. Rejection reasons are mandatory and grouped by category. The activity
log is exportable, and the export is itself audited.

This surface exists partly to serve customers' own obligations: draft King V's
technology chapter covers AI governance explicitly, and Ghana's 2026 BoG
directive requires AI/ML governance for fraud and credit models.

### UX

Assist appears next to the field or record it helps with — never a separate area
users must remember to visit. Every suggestion renders in `AiReviewPanel` with
the draft, its confidence, its citations, and Accept / Edit / Reject with a
mandatory reason on reject. Atlas is a drawer, available from any page, and its
conversation lives only in the browser. Both hide themselves entirely when the
feature flag is off or the user lacks the permission.

### Routes

`POST /ai/assist`, `GET /ai/interactions/{interaction}`,
`POST /ai/interactions/{interaction}/decide`,
`POST /ai/interactions/{interaction}/feedback`, `POST /ai/atlas`;
`/admin/ai`, `/admin/ai/log`, `/admin/ai/log/export`, `/admin/ai/prompts`,
`PUT /admin/ai/capabilities`, `PUT /admin/ai/budget`.

Feature flags: `ai-assist`, `ai-atlas`, `ai-governance`.

### Operational notes

Without `ANTHROPIC_API_KEY` the layer is **dormant**, not broken: every
capability reports itself unavailable rather than failing mid-request. A fresh
installation also ships with every capability *disabled* — `AiGateway` treats a
missing `ai_configurations` row as off, so switching one on is a deliberate,
audited administrative act.

The transport is Laravel's HTTP client rather than a vendor SDK. The phase's own
acceptance test is "mock the HTTP client and inspect the outbound body", which
`Http::fake()` makes a first-class assertion against the real transport;
`AnthropicClient` is the single class that reads the key, which is what makes
"the key never leaks" one place to check rather than a codebase-wide audit.

## Mobile, offline & omnichannel (Phase 15)

Makes the platform usable by a control owner in a branch on a mid-range Android
over 4G with unreliable power — and reachable on WhatsApp, SMS, push and USSD.
Every channel ships **dormant**: with the `.env` credentials blank the drivers
record `skipped` message-log rows instead of calling out, so go-live is
configuration, not code.

### Offline PWA (15.1)

`resources/js/offline/` holds an IndexedDB store (no library), a durable outbox
and the sync engine. `GET /offline/bootstrap` returns the trimmed working set —
open test instances with their check items, outstanding attestations *with the
signable text*, pending CSA questionnaires, my controls — which the device
caches. Offline-capable actions (complete an attestation, answer a CSA, record a
check result, capture compressed photo evidence, add an exception comment) queue
with a client-generated UUID and replay **in order** through `POST
/offline/outbox`, where `OfflineSyncService` validates each exactly as if
submitted online: same policies, same services, same audit trail.

Three structural rules: the action whitelist means approvals, reviews and
SoD-gated transitions are not blocked but *unhandleable* — rejected with a reason
the UI shows (R2); the client UUID makes every replay idempotent (a retry after a
dropped response returns the recorded outcome); and a payload carrying an
`expected_updated_at` older than the server copy parks as `conflict` with the
server state attached — the sync indicator shows a "server changed this" prompt,
never a silent overwrite.

### Mobile interfaces (15.2)

`/my-tasks` is the one-card-per-item, one-tap task view (44px+ targets, works
offline from the cached feed). `CameraCapture` re-encodes photos through a
canvas — which strips EXIF including embedded GPS — and compresses to ≤500KB;
geotagging is an explicit, tenant-enabled option that attaches coordinates as
metadata, never inside the file. Files over 2MB go through resumable chunked
uploads (`chunked_uploads` table; assembly hands off to `EvidenceService::store`
so a chunked upload is indistinguishable from a direct one). Forms autosave to
localStorage via `useAutosave`.

### WhatsApp Business Cloud API (15.3)

`WhatsAppService` enforces the two channel laws architecturally: every outbound
payload passes `OutboundContentGuard` (notification + platform link only — long
digit runs, emails and off-platform links are blocked before any HTTP call,
because message content traverses Meta infrastructure, NDPA), and the 24-hour
session window (free-form inside it, **Meta-approved template only** outside it —
`whatsapp_templates.approval_status` mirrors Meta's review state and seeds as
`draft`; "Sync from Meta" on `/admin/messaging` pulls real statuses). The inbound
webhook is signature-verified (`X-Hub-Signature-256`); delivery statuses update
`message_logs`; and a message opening with the tenant's Speak-Up keyword
(`settings.cases.whatsapp_intake_keyword`, default `REPORT`) goes through the
**anonymising bridge**: `CaseService::openFromAnonymisingBridge` persists the
case with no identifier at all, the reporter token is replied over the still-open
session without ever logging the number (NCCG RP 19, CBN whistleblowing
guidance), and the log row records that anonymisation happened.

### SMS, USSD & push (15.4/15.5)

SMS goes through a driver abstraction (Termii, Africa's Talking, or the default
`log` driver) with the same content guard, templated bodies (`sms_templates`),
delivery-receipt webhook and per-tenant cost tracking in minor units + ISO
currency (R7). USSD is the one flow worth a menu — confirming an outstanding
attestation from any handset — behind the `ussd` feature flag (off by default)
plus a gateway token, recorded with `method = 'ussd'`. Web Push is deliberately
**payload-free**: the push wakes the service worker, which fetches
`/notifications/latest` over the authenticated session — no notification content
ever rests on a push relay, and no RFC 8291 payload encryption to maintain.
Subscriptions are per-device rows in `push_subscriptions`; VAPID keys live in
`.env`.

### Low-bandwidth mode & the bundle budget (15.6)

The Phase 7 toggle now kills webfonts (system stack), animations and chart
transitions via the `low-bandwidth` root class, and the offline bootstrap trims
every list to what renders. `npm run build` runs `scripts/bundle-budget.mjs`,
which **fails the build** if any chunk exceeds 250KB gzipped (R6) — the budget is
CI-enforced, not aspirational.

### Routes

`GET /my-tasks`, `GET /offline/bootstrap`, `POST /offline/outbox`,
`GET /notifications/latest`, `POST|DELETE /push/subscriptions`,
`POST /uploads/chunked` (+ `/chunks`, `/complete`, `DELETE`);
`GET /admin/messaging`, `POST /admin/messaging/sync-templates`;
public webhooks (each with its own auth): `GET|POST /webhooks/whatsapp`,
`POST /webhooks/sms/receipts`, `POST /webhooks/ussd`.

Permissions: `manage messaging`. Feature flags: `ussd` (ships off).
New tables: `whatsapp_templates`, `sms_templates`, `message_logs`,
`whatsapp_sessions`, `push_subscriptions`, `offline_actions`,
`chunked_uploads`; `users.phone` added.

## Multi-entity, data residency & enterprise readiness (Phase 16)

**Group & entity hierarchy (16.1).** `entities` is the legal-entity register for
banking groups and holding companies: type (holding → representative office,
mirroring the licence classes the platform serves), jurisdiction, licence
categories, regulators, fiscal year end, functional currency, consolidation
method (`full` / `proportional` / `equity` / `none`) and ownership percentage.
An entity anchors an organisation-unit subtree (`organisation_unit_id`), which
is how the existing registers scope to it without a schema change: controls via
`unit_id`, risks/metrics/obligation assignments via their existing
`entity_id → organisation_units` columns (R8 — nothing existing moved).
`ConsolidationService` computes **standalone** numbers per entity (a parent's
subtree minus any subtree claimed by a nested entity, so nothing is counted
twice), weights them by consolidation method for the group position, and
benchmarks each entity against the group average. Cached per tenant
(`TenantCache`, explicit invalidation on entity writes).

**Per-entity access is a grant, not a role.** `entity_user` rows are the only
thing that opens a subsidiary's data: `EntityPolicy::view()` requires a grant
even of a System Administrator, and the group dashboard computes its totals
over exactly the granted set, so a hidden subsidiary's numbers cannot be
inferred from a total. Granting is its own permission (`grant entity-access`)
and every grant/revoke is audited.

**Data residency (16.2).** `ResidencyGuard` enforces the tenant's declaration
(`tenants.data_residency`) at the four layers data can leave through —
filesystem writes (`EvidenceService`), queue dispatch
(`Queue::createPayloadUsing` in `AppServiceProvider`), backups
(`atheris:backup`) and outbound integrations (`IntegrationService::send`) —
against the region maps in `config/residency.php`. A mismatch **blocks and
audits** (`residency-blocked`); there is no flag, feature toggle or environment
switch that disables the guard, and changing a declaration at runtime throws
(`Tenant`/`Entity::booted()`) — re-provisioning is the only path, and it is
audited. The **cross-border transfer register** (`cross_border_transfers`)
refuses a transfer without a usable lawful basis (NDPA Part VIII grounds seeded
in `transfer_lawful_bases`, unverified until confirmed against the gazette —
R10), and recorder ≠ authoriser (R2). The **residency attestation**
(`ResidencyAttestationService`) derives a signed statement — data categories ×
storage target × region, enforcement posture, the period's transfers — from
live configuration, hashes it (SHA-256), signs it (HMAC over the checksum) and
renders the PDF a customer hands to CBN, BoG or NDPC. Deployment reference:
`docs/deployment/nigeria.md` (Rack Centre, MainOne/Equinix LG1, Galaxy
Backbone, OADC; control-plane vs data-plane split) and
`docs/deployment/gh-ke-za.md`.

**Enterprise hardening (16.3).** Structured JSON logging (`structured` channel)
with a request correlation id (`AssignRequestId`, echoed as `X-Request-Id`);
`/health` reporting database/cache checks and queue depth (`/up` stays the
liveness probe); security headers on every response (`SecurityHeaders`: CSP,
HSTS behind TLS, X-Frame-Options DENY, nosniff, Referrer-Policy,
Permissions-Policy); login lockout (5 attempts, Breeze throttle);
dependency vulnerability scanning in CI (`.github/workflows/ci.yml`: `composer
audit` + `npm audit` fail the build); a query-budget regression test on the
controls index at 400 rows; DR runbook with a monthly restore drill
(`docs/runbooks/disaster-recovery.md`, `scripts/restore-drill.sh`);
responsible disclosure in `SECURITY.md`; and `tenant:provision` standing up a
complete tenant in one command. Production queue topology (Redis + Horizon) is
documented per country plane in `docs/deployment/`.

**Statement of Applicability (16.4).** The shipped ISO/IEC 27001:2022 pack
(`ISO-27001-2022`, all 93 Annex A controls in 4 themes plus clauses 4–10)
drives `SoaService`: one row per leaf requirement joining the recorded
applicability decision (`soa_entries` — excluding a control requires a
justification, which is what a certification auditor asks for first) with the
live approved control mappings, so the SoA is derived, never typed. Exported
as PDF from the framework page (`is_certifiable` frameworks). The demo tenant
dogfoods it: Atheris's own operating controls mapped to Annex A with decisions
recorded (`Phase16DemoSeeder::seedIsmsSoa()`).

### Routes

`GET /entities`, `POST /entities`, `PUT /entities/{entity}`,
`POST /entities/{entity}/grants`, `DELETE /entities/{entity}/grants/{user}`,
`GET /group`; `GET /residency`, `POST /residency/transfers`,
`POST /residency/transfers/{transfer}/authorise` (+ `/complete`),
`POST /residency/attestations`,
`GET /residency/attestations/{attestation}/download`;
`GET /frameworks/{framework}/soa` (+ `PUT`, `GET …/export`); `GET /health`.

Permissions: `view entities`, `manage entities`, `grant entity-access`,
`view group-dashboard`, `view residency`, `manage residency`,
`record transfers`, `authorise transfers`, `manage soa`.
Feature flags: `entities`, `residency`.
New tables: `entities`, `entity_user`, `transfer_lawful_bases`,
`cross_border_transfers`, `residency_attestations`, `soa_entries`;
`organisation_units.type` gains `Subsidiary`.

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
- **Regulatory notification windows** — `NotificationObligationResolver` resolves them from the seeded obligation records' `due_rule`; never a constant in PHP
- **Complaint acknowledgement time** — server-stamped in `ComplaintService::acknowledge()`, which takes no time argument; the field is stripped in `ComplaintRequest`
- **Complaint penalty exposure** — `ComplaintService::penaltyExposure()` from the obligation records' `penalty_amount_minor` / `penalty_basis`; part weeks round up
- **Incident closure gate** — `IncidentService::close()`: no open mandatory action, no outstanding notification
- **Case allowlist, no admin bypass** — `InvestigationCase`'s `allowlist` global scope + `InvestigationCasePolicy::grantsAccessTo()`
- **An intake that names nobody still reaches someone** — `CaseService::intakeAllowlist()`; roles from `tenants.settings['cases']`
- **Case anonymity** — `InvestigationCase::auditsAnonymously()` and a SHA-256 reporter token; there is deliberately no method that turns a case back into a person
- **Complaint resolution window, incident event map** — `tenants.settings['complaints']` / `['incidents']`, defaults in the respective services
- **A monitoring rule cannot activate on one person's say-so** — `MonitoringService::approveRule()` + `MonitoringRulePolicy`; author ≠ approver, owner ≠ approver, no admin bypass
- **Editing what an active rule tests invalidates its approval** — `MonitoringService::touchesApproval()` against `MonitoringRule::APPROVAL_SENSITIVE`
- **Rule definitions are parsed against a per-type schema, never trusted** — `RuleEngineService::schemas()` / `validateDefinition()`, run at save time by `MonitoringRuleRequest`
- **custom_sql is whitelisted, not denylisted, and never touches the app database** — `App\Support\SqlGuard` + the in-memory SQLite sandbox in `RuleEngineService::sandbox()`
- **Sampling is reproducible** — `SamplingService` is a deterministic function of (population, config, seed); the seed is written onto `monitoring_runs`
- **Automated results never sign themselves off** — `MonitoringService::linkTestInstance()` stops at `Submitted`; review and rating stay human and rating still needs a second person
- **Circuit-breaker threshold, rate limit and timeout** — columns on `data_sources`, applied by `ConnectorManager`; never constants
- **Sensitive personal data is hashed at ingestion unless the DPO says otherwise** — `DataSourceDataset::requiresHashing()` + `SnapshotService::redactRow()`
- **Snapshots cannot leave their country's data plane** — `SnapshotService::pathFor()` is the single place that decides, and it always starts with the tenant's residency code
- **Legal hold beats retention** — `DataSnapshot::purgeable()` excludes it, `SnapshotService::purge()` refuses it, and `DataSnapshot::booted()` throws if anything gets past both
- **AI output cannot become a record without a named human** — `AiDraft::accept()` → `AcceptedDraft`, whose constructor re-reads the interaction and refuses to exist over a `Pending` row; domain services type the parameter, so the rule is the type system's, not a convention
- **AI never widens anyone's reach** — every capability requires its manual equivalent's domain permission, resolved through `CapabilityRegistry::permission()` and **failing closed** on an unresolvable key
- **Retrieval is permission-filtered in SQL** — `AiKnowledgeChunk::scopePermittedTo()`, applied in the candidate query; a user with no permissions retrieves nothing, not everything
- **Redaction cannot be skipped** — a mandatory `AiGateway` stage plus an egress re-check that abandons the call; there is no per-request switch
- **Model identifiers, prompts, budgets and capability toggles are data** — `ai_configurations`, `ai_prompts`, `ai_budgets`, `config/ai.php`; no model name or prompt lives in a class
- **A prompt version is never edited** — `PromptRegistry::publish()` always writes a new row, because `ai_interactions` references the version that ran (R3)
- **AI-proposed obligations ship unverified and inactive** — `RegulatoryParseCapability::draftObligations()` (R10)
- **The API key is read in exactly one class** — `AnthropicClient`, which also scrubs it from anything bound for storage
- **The residency guard has no off switch** — `ResidencyGuard` reads only `config/residency.php`; no database flag, no feature flag, no env kill switch; every block audited as `residency-blocked`
- **A residency declaration changes only by re-provisioning** — `Tenant`/`Entity::booted()` throw on a runtime edit; `tenant:provision` binds `residency.reprovisioning` for the one legitimate path
- **No lawful basis, no transfer** — `cross_border_transfers.lawful_basis_id` is NOT NULL, `CrossBorderTransferService::record()` re-checks the basis is usable, and the form validates it; recorder ≠ authoriser in policy *and* service
- **An entity opens only by grant** — `EntityPolicy::view()` requires an `entity_user` row on top of the permission, for every role; group totals are computed over the granted set only
- **Consolidation math is the entity record's** — method and ownership live on `entities`, applied by `Entity::consolidationWeight()`; never a constant
- **The SoA is derived, never typed** — `SoaService::statement()` joins recorded decisions to live approved mappings at read time; an exclusion without a justification fails validation

## Quality gate

```bash
composer test        # 707 feature/unit tests incl. SoD, legal-hold, dual-approval,
                     # due-rule and penalty maths, content-pack idempotency,
                     # API-auth bypass attempts, distribution idempotency,
                     # CSA rating gates, survey anonymity, import rollback,
                     # residual-engine parity and the 20% floor, Monte Carlo
                     # determinism, a custom 4×4 heatmap scale, every threshold
                     # operator, expression-evaluator injection attempts,
                     # KRI-breach → exception linkage and appetite escalation,
                     # the 24-hour acknowledgement clock across a DST edge,
                     # per-week penalty accrual with part weeks rounded up,
                     # an administrator getting 403 on a case allowlist,
                     # anonymity proven on the raw row and the audit trail,
                     # the incident closure gate, an obligation edit
                     # moving a live notification countdown, every rule type
                     # against fixtures with known outcomes, sampling
                     # reproducibility under a fixed seed, 24 custom_sql
                     # rejection cases, the circuit breaker opening on the
                     # Nth failure, credentials absent from the raw row,
                     # the audit trail and the Inertia props, PII hashed at
                     # ingestion, snapshots confined to their country's data
                     # plane, legal hold defeating the purge sweep, no PII in
                     # an outbound model payload, an unreadable record never
                     # reaching a prompt, an undecided AI draft refusing to
                     # become a record, an advisory capability refusing to
                     # touch a test result, a budget stop that makes no call
                     # and still leaves a trace, and the API key absent from
                     # the request body, the stored interaction and the page,
                     # the residency guard blocking all four egress layers,
                     # a declaration refusing to change at runtime, a transfer
                     # with no lawful basis never becoming a row, recorder ≠
                     # authoriser on transfers, consolidation math per method,
                     # a rollup leaking nothing ungranted (admin included),
                     # tenant-scoped cache keys with explicit invalidation,
                     # a tamper-evident attestation signature, the 93 Annex A
                     # controls present in the SoA, and a query budget held
                     # on the controls index at 400 rows
composer lint        # Pint
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
calculated expressions, and the universal linkage graph.

**v2.0 Phase 11 (Policy, Incident, Complaints & Case Management) is implemented**:
the policy lifecycle with section-level structure, automatic attestation campaigns
on publication, time-boxed waivers and policy gap analysis; incident management with
Basel loss capture, control-failure linkage and a regulatory notification engine
that reads every window from the obligation register; CBN consumer-protection
complaint handling with a server-stamped acknowledgement clock, live penalty
exposure, root-cause analysis and the CPD return; and allowlist-only case management
with genuine whistleblowing anonymity, a one-way reporter token and a public
Speak-Up intake.

**v2.0 Phase 12 (Continuous Controls Monitoring & Connectors) is implemented**:
the read-only connector framework with encrypted credentials, a per-source circuit
breaker and rate limiting; five exercised Priority 1 connectors and nine
documented-but-unverified vendor connectors covering the core-banking, ERP,
payments and tax systems Nigerian and African institutions actually run; an
eleven-type rule engine with reproducible sampling and a genuinely sandboxed
`custom_sql`; continuous results that land in the existing test-instance,
effectiveness-rating and exception machinery rather than beside it;
segregation-of-duties analysis over entitlement extracts with time-boxed
acceptance; the CCM dashboard with key-control coverage; and snapshot storage that
hashes sensitive personal data at ingestion, stays inside its tenant's country
data plane, and cannot be purged under legal hold.

**v2.0 Phase 13 (Dashboards, Analytics & Reporting v2) is implemented**: the
configurable dashboard builder over a 44-dataset permission-scoped widget registry
with drift-free drill-down and org-tree rollup; nine shipped role dashboards
including the design/operating effectiveness pair; an in-house, colour-vision-validated
chart layer that costs 7 KB gzipped; the report designer with six output engines,
variable interpolation, maker-checker approval and classification-aware scheduled
distribution; deterministic forward-look analytics; and the six Nigerian regulatory
submission generators with completeness gating, unverified-content exclusion and
three-person maker-checker filing.

**v2.0 Phase 14 (AI Layer) is implemented**: a governed Anthropic layer where the
gateway is the only route to the provider and its pipeline — capability gate,
authorisation, budget, rate limit, permission-filtered retrieval, unskippable
redaction, versioned prompt, schema validation with one repair, confidence
scoring, citation resolution, audit — runs in that order for every one of the
nine capabilities. AI never decides, and not as a convention: an `AiDraft`'s
payload has no getter, the only way out is `accept(User)`, and the resulting
`AcceptedDraft` refuses to exist unless a human decision is already recorded
against the interaction by the person applying it. Retrieval filters on
`permission_key` in SQL before ranking, so a user is never answered from records
they cannot open; embeddings are computed locally rather than shipping tenant
content to a third party. Redaction is verified again at egress. Spend is capped
per tenant, capability and user, checked before anything is assembled. The
governance surface reports the acceptance rate — the honest metric — alongside a
versioned prompt history with diffs and an exportable activity log.

Carried forward from this phase: the local hashed embedding driver is lexical
rather than semantic and will not connect synonymous control language, so an
in-country embedding model is the natural next step (a driver plus a re-index);
streaming is not wired, so Atlas answers arrive whole rather than progressively;
and `narrative.generate` returns text for a human to paste rather than writing
into a report section, which is deliberate but does mean an extra step.

**v2.0 Phase 15 (Mobile, Offline & Omnichannel) is implemented**: the offline
PWA with an IndexedDB working set and a durable, idempotent, ordered outbox
whose whitelist makes approvals and SoD transitions structurally unreachable
from a queue; conflict surfacing instead of silent overwrite; the `/my-tasks`
one-tap mobile view; EXIF-stripping ≤500KB camera capture and resumable chunked
uploads that feed the normal evidence pipeline; WhatsApp Cloud API with the
notification-and-link-only content guard, Meta template approval gating, the
24-hour session window, signed inbound webhooks and the anonymising bridge for
Speak-Up reports; SMS drivers with receipts and minor-unit cost tracking;
feature-flagged USSD attestation; payload-free VAPID Web Push; and a CI-enforced
250KB-gzipped route-chunk budget. Every channel is dormant until its `.env`
credentials exist — go-live is configuration, not code.

**v2.0 Phase 16 (Multi-Entity, Data Residency & Enterprise Readiness) is
implemented**: the legal-entity register with consolidation methods and
grant-only per-entity access; the consolidated group dashboard with standalone
(never double-counted) entity numbers, weighted group totals and benchmarking;
the runtime-immutable residency declaration enforced by a guard at the
filesystem, queue, backup and integration layers with no off switch; the
cross-border transfer register with mandatory NDPA lawful bases and
recorder ≠ authoriser; signed, tamper-evident residency attestations; per-country
deployment documentation (NG/GH/KE/ZA, control-plane vs data-plane); structured
logging with correlation ids, health endpoints, security headers, CI dependency
scanning, a DR runbook with restore drill, one-command tenant provisioning; and
the dogfooded ISO 27001:2022 Statement of Applicability over the shipped
93-control Annex A pack.

Carried forward from this phase: WhatsApp templates must be created and approved
in Meta Business Manager before the channel is honest about sending (the seeder
ships them as drafts); offline evidence blobs replay without a server-side
idempotency key, so an interrupted sync can in principle duplicate an upload
(the checksum makes duplicates detectable); and the outbox replays when the app
is next opened online rather than via background sync while closed.

Still pending from the v1 backlog: webhook
subscriptions, NexusRisk risk-register pull, and in-place evidence redaction with
data-subject-request tooling. Carried forward from this phase: the nine Priority 2
connectors need a live environment each before their `verified` flag can be
flipped, and `custom_sql` currently reads a copy of the snapshot rather than the
source.
