# 00 — System Map (Phase 0 Discovery)

**Product:** SecondLine — Atheris Control Solution
**Repository:** `atheris-control`
**Branch:** `phase-17-extended-grc`
**Commit at discovery:** `8d233c7`
**Date:** 2026-08-14
**Prepared by:** QA Automation / Test Analysis
**Method:** Derived from source code (routes, migrations, models, services, policies, seeders, scheduler). Not derived from the BRD or the README.

> **Status of this document.** This is the Phase 0 deliverable required before any test executes. It records what the system *is*, then reconciles that against BRD v1.0. Two reconciliation findings below (**R-01** and **R-02**) change the test plan itself and must be settled before Phase 2 begins, or the run will produce false Criticals.

---

## 1. Environment as discovered

| Item | Value | Note |
|---|---|---|
| `APP_ENV` | `local` | Not production — ground rule 1 satisfied |
| `APP_DEBUG` | `true` | Must be `false` for the security checks in Part B §10 |
| `APP_URL` | `http://localhost` | |
| `DB_CONNECTION` | `mysql` → `thirdLine-control` @ `127.0.0.1` | **Server not reachable** (see E-01) |
| `QUEUE_CONNECTION` | `database` | |
| `MAIL_MAILER` | `log` | Acceptable mail catcher for Part B §2 |
| PHP | 8.3+ required; `pdo_mysql`, `pdo_sqlite`, `sqlite3` all present | |
| Test harness | PHPUnit, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync` | `phpunit.xml` |

**Stack:** Laravel 13 · Inertia.js 3 · React 18 · Tailwind 3 · Spatie Permission 8 · Ziggy · dompdf · PhpSpreadsheet · PhpWord · google2fa · onelogin/php-saml.

### E-01 — Environment blocker (Phase 1)

`mysqld` / `mysql` / `mariadb` are **not installed** on this host and Docker is **not running**. `php artisan db:show` fails with `SQLSTATE[HY000] [2002] Connection refused`. The application as configured cannot be started, migrated or seeded.

The automated suite is unaffected — it runs on SQLite in memory — but browser-driven, API, escalation-timing and report-generation testing all require a live application.

Two routes forward, both of which need a decision (see §11, Open Decisions):

- **(a) Install and run MySQL** — matches production; no divergence risk.
- **(b) Run the application on SQLite** — fast, but MySQL-specific behaviour goes untested: `enum` column constraints (this schema uses `enum` heavily for every status field), `utf8mb4` collation and emoji storage, `JSON` column semantics, and MySQL-specific locking. Any of those could mask a real defect, and all would need recording in `04-coverage-gaps.md`.

Recommendation: **(a)**. The schema's reliance on `enum` for every state machine means an invalid-status write that MySQL rejects at the storage layer may be silently accepted by SQLite, which would invalidate a material part of the workflow test suite (Part B §7).

---

## 2. Scale of the system under test

| Dimension | Count |
|---|---|
| Routes (excl. `HEAD`) | **502** (262 POST, 178 GET, 43 PUT, 22 DELETE, 8 PATCH, 1 OPTIONS) |
| Migrations | **161** |
| Eloquent models | **125** |
| Service classes | **69** (+ `Services/Ai`, `Services/Messaging` sub-packages) |
| Controllers | **80+** (incl. `Admin/`, `Api/`, `Auth/`) |
| Authorisation policies | **45** |
| Form Request classes | **59** |
| Inertia page components | **130** |
| Components calling `useForm` | **78** |
| Console commands | **25** |
| Scheduled tasks | **22** |
| Queued job classes | **1** (`IndexKnowledgeRecord`) |
| Notification classes | **14** |
| Integration connectors | **13** across 6 families |
| Existing automated tests | **69 files** (63 Feature, 6 Unit) = **784 test methods**; full suite verified green: 2,863 assertions, exit 0 |
| BRD v1.0 functional requirements | **106** (FR-1.1 … FR-12.8) + 11 NFRs |

Full route table with verb, URI, name, handler and middleware: [`evidence/00-route-table.tsv`](evidence/00-route-table.tsv).

**Implication for the test plan.** Part B §1 scopes 19 modules. The build spans roughly 35 domains across 17 delivery phases. Executing the Part B matrix at full depth against every domain is a materially larger exercise than the plan assumes — see §10, Scope reconciliation.

---

## 3. Authorisation model

Three independent gates stack on most routes. A test that only proves one of them proves nothing about the others.

| Gate | Mechanism | Alias |
|---|---|---|
| Role | Spatie `RoleMiddleware` | `role:System Administrator\|Control Function Head` |
| Permission | Spatie `PermissionMiddleware` | `permission:close exceptions` |
| Feature flag | `App\Http\Middleware\EnsureFeatureEnabled` | `feature:vendors` |
| Model-level | 45 Laravel policies | `$this->authorize(...)` |
| Tenant | `BelongsToTenant` global query scope | implicit on every domain model |
| Entity | Explicit per-entity grants (`EntityLink`) | Phase 16 — permission alone is never sufficient |
| Integration | `AuthenticateIntegration` (`X-Api-Key`) | `integration.auth` on `/api/v1/*` |

Global middleware: `AssignRequestId` (prepended), then `HandleInertiaRequests` → `EnforceMfa` → `SecurityHeaders` (appended).

**CSRF exemptions** (`bootstrap/app.php`): `auth/sso/*/acs` and `webhooks/*`. Both are asserted to carry their own proof — SAML signature + replay protection, and HMAC/shared-token for webhooks. Both claims require independent verification (see TC-New-01, TC-New-02 in §9).

### 3.1 Roles as implemented

Six roles, seeded in `database/seeders/RolePermissionSeeder.php` against **~180 named permissions**.

| Role | Shape |
|---|---|
| **System Administrator** | All permissions. Holds `view all cases` (sole holder) — read-only case oversight, every view logged. |
| **Control Function Head** | All permissions **except** `manage users`, `manage settings`, `manage sso`, `install content-packs`, `view all cases`. **The only role that may close an exception.** |
| **Control Officer** | Second-line author/operator. Creates controls, risks, tests, exceptions, monitoring rules, policies, reports. **Cannot** approve, publish, close or file. |
| **Control Owner** | First line. Remediates, uploads evidence, responds to campaigns, prepares GHG data. **Cannot** close its own exceptions. |
| **Line Manager** | Read-across + escalation tier 1. Authors nothing. |
| **Executive Viewer** | Board tier. Read-only + `approve appetite` and `approve objectives`. Case access limited to `report cases` (aggregate counts only). |

### 3.2 Segregation of duties enforced in code (FR-12.3)

These are the highest-value negative tests in the entire engagement. Each is enforced at **two** layers — policy *and* service — which is the correct design and must be verified at both.

| Rule | Policy | Service |
|---|---|---|
| Only Control Function Head closes an exception | `ExceptionPolicy::close` | `ExceptionService::verifyAndClose` |
| Closure only from status `Remediated` | `ExceptionPolicy::close` | `ExceptionService::transition` |
| The tester who ran the source test cannot close | `ExceptionPolicy::close` → `userPerformedSourceTest` | same |
| The owner of the failed control cannot close | `ExceptionPolicy::close` | `verifyAndClose` |
| The exception's own owner cannot close | `ExceptionPolicy::close` | *(policy only — see G-01)* |
| Risk acceptance needs CFH or SysAdmin, and not the control owner | `ExceptionPolicy::acceptRisk` | `ExceptionService::acceptRisk` |
| Generate ≠ approve ≠ file on submission packs | — | `SubmissionPackService` |
| Prepare ≠ verify GHG data | — | `SustainabilityService` |
| Author ≠ approve objectives | — | `ObjectiveService` |

**Note the deliberate absence of a System Administrator bypass** on exception close/remediate — documented in `ExceptionPolicy`. TC-10-06 will therefore pass, but must still be executed via a direct endpoint call, not only through the UI.

#### G-01 — Discovery gap to probe
`ExceptionService::verifyAndClose()` re-checks role, source-tester and control-owner, but does **not** re-check `$exception->owner_id !== $verifier->id` — that guard exists only in `ExceptionPolicy::close`. Any code path reaching the service without the policy (a console command, a queued listener, a future controller) would skip it. Flagged for targeted testing in TC-10-09; not logged as a defect until a reachable path is demonstrated.

---

## 4. Data model — core domain

161 migrations. The controls-testing core, which carries the BRD's Must requirements:

| Table | Key columns / constraints |
|---|---|
| `tenants` | licence key, data residency, settings |
| `controls` | `type` enum(Preventive/Detective/Corrective), `nature` enum(Manual/Automated/Hybrid/IT-Dependent Manual), `frequency` enum(Daily…Event-driven), `status` enum(Draft/Pending Approval/Active/Under Review/Retired), `owner_id`, `unit_id`, `category_id` |
| `control_versions` | Prior versions retained with effective dates (FR-1.7) |
| `test_scripts` / `check_items` | Reusable checklists, `default_severity_on_fail` per item |
| `test_instances` | `status` enum(Scheduled/In Progress/Submitted/Reviewed/Closed/Reopened), `assigned_tester_id`, `reopen_reason` |
| `check_results` | Pass/Fail/N-A + comment |
| `control_exceptions` | `source_type` enum(Test/Spot Check/Manual), `severity` enum(Critical/High/Medium/Low), `status` enum(Open/Assigned/In Progress/Remediated/Verified-Closed/Risk Accepted), `age_days`, `is_overdue`, `extension_count`, `verified_by`, `verified_at`, `verification_method`, `risk_acceptance_expiry`, `recurrence_of_exception_id` |
| `exception_activities` | Timestamped, attributed commentary thread (FR-5.10) |
| `compensating_controls` | `status` enum(Proposed/Approved/Active/Expired/Withdrawn) |
| `spot_checks` / `findings` | `status` enum(Draft/In Progress/Completed/Report Issued) |
| `effectiveness_ratings` | Design + operating recorded separately; `rating_matrix_entries` derives overall |
| `risks` / `control_risk` | Many-to-many; inherent + residual |
| `evidence` | PII classification, retention, legal hold, dual-approval disposal |
| `evidence_access_logs` | Every view and download (FR-9.6) |
| `audit_trails` | `entity_type`, `entity_id`, `action`, `before` json, `after` json, `ip_address`, `user_agent`, `created_at` only |
| `escalation_matrices` / `escalation_events` | Per-tenant, keyed on severity |
| `integration_configs` / `integration_sync_logs` | `target_system` enum(ThirdLine/NexusRisk), `master_system` enum(SecondLine/ThirdLine/PerCategory) |

**Tenancy.** Branch-per-client deployment, but every domain table carries `tenant_id` as defence in depth. `App\Models\Concerns\BelongsToTenant` adds a global scope and auto-fills on create. Shared reference data uses `tenant_id = NULL`. Several services deliberately call `withoutGlobalScopes()` (e.g. `ExceptionService::refreshAgeing`, `userPerformedSourceTest`, `ResidualRiskService::recompute`) — **each such call is a candidate cross-tenant leak and must be tested individually** (Part B §8).

**Audit immutability.** `AuditTrail::booted()` throws `LogicException` on `updating` and `deleting`. This is **model-layer only** — a query-builder write (`DB::table('audit_trails')->update(...)`), a raw statement, or a direct DB connection bypasses it entirely. There is no database-level protection (no trigger, no append-only grant). TC-17-02 must state precisely which of these it proves.

---

## 5. State machines

### 5.1 Control (FR-1.9) — `ControlService::TRANSITIONS`
```
Draft            → Pending Approval
Pending Approval → Active | Draft
Active           → Under Review | Retired
Under Review     → Pending Approval | Active | Retired
Retired          → (terminal)
```
Approval is maker–checker (`ControlService::approve`). On approval the control is published to ThirdLine (FR-11.1) — failures log and never block.

### 5.2 Test instance (FR-3.7) — enforced procedurally in `TestingService`
```
Scheduled → In Progress → Submitted → Reviewed (approved)
                             ↓ (rejected)
                        In Progress
Reviewed  → Reopened  (formal reopen, reason captured — FR-3.9)
```
`review()` guards `status !== 'Submitted'`. Sign-off publishes the result to ThirdLine (FR-11.2).

### 5.3 Exception (FR-5.3) — `ExceptionService::TRANSITIONS`
```
Open            → Assigned | In Progress | Risk Accepted
Assigned        → In Progress | Remediated | Risk Accepted
In Progress     → Remediated | Risk Accepted
Remediated      → Verified-Closed | In Progress      (failed verification returns it)
Verified-Closed → (terminal)
Risk Accepted   → (terminal, but see below)
```
`OPEN_STATUSES = [Open, Assigned, In Progress, Remediated]`.

**Terminal-state caveat.** `Risk Accepted` is declared terminal in `TRANSITIONS`, yet `refreshAgeing()` moves expired risk acceptances back to `In Progress` via a direct `update()` that **bypasses `transition()`**. This is intended behaviour (FR-5.8 periodic re-confirmation) but it means a terminal state is mutable by the scheduler. TC-10-10 and Part B §7.6 must test this deliberately: confirm the reopen path is the *only* one, and that it is logged.

**Auto-raise sources.** Exceptions are raised from five sources, not the two in the BRD: `raiseFromCheckResult` (test), `raiseFromFinding` (spot check), `raiseFromMonitoringRun`, `raiseFromMonitoringFinding`, and SoD violations. Each sets `status` to `Assigned` if the control has an owner, else `Open`.

**Default target closure by severity** (`defaultClosureDays`): Critical 7d, High 14d, Medium 30d, Low 60d.

### 5.4 Spot check — `SpotCheck::STATUSES`
```
Draft → In Progress → Completed → Report Issued
```
Guards are `abort_if`/`abort_unless` in `SpotCheckController`, **not** a transition table — an inconsistency with the other three lifecycles and a likelier place for an illegal transition to slip through. Test at endpoint level.

### 5.5 Compensating control
```
Proposed → Approved → Active → Expired | Withdrawn
```
`ExpireCompensatingControls` command auto-expires at end date and re-escalates the underlying exception (FR-6.5).

---

## 6. Calculations to recompute independently (Part B §2, "every calculation")

| Calculation | Location | Rule |
|---|---|---|
| Residual risk | `ResidualRiskService::recompute` | `residual = inherent × (1 − weighted mean reduction)`, **floored at 20% of inherent**. Reduction factors: Effective `0.5`, Partially Effective `0.25`, Ineffective `0.0`, Not Tested `0.0`. Counts Active controls + approved active compensating controls. |
| Overall effectiveness | `rating_matrix_entries` (configurable, `RatingMatrixSeeder`) | Default per FR-7.4: a design-ineffective control cannot exceed Partially Effective overall. **Test the full design × operating matrix.** |
| Exception ageing | `ExceptionService::refreshAgeing` | `age_days = date_raised → today`; `is_overdue = today > target_closure_date`. Uses `updateQuietly()` — so **ageing changes do not fire model events and are not audit-logged**. Confirm that is intended. |
| Default closure date | `ExceptionService::defaultClosureDays` | 7 / 14 / 30 / 60 days by severity |
| Dashboard tallies | `DashboardService` | Reconcile every tile to SQL |
| Test completion / overdue rates | `TestingService` | Per unit, per owner, per period |

---

## 7. Scheduler, jobs and notifications

22 scheduled tasks. The ordering is deliberate and is itself a test target — several jobs are sequenced so that overnight work escalates on the *same* morning rather than a day late.

| Time | Command | Relevance |
|---|---|---|
| 01:00 | `secondline:generate-test-instances` | TC-07-01 |
| 01:15 | `secondline:refresh-ageing` | TC-10-11 |
| 01:30 | `secondline:expire-compensating-controls` | TC-11-04 |
| 01:30 | `atheris:backup` | NFR-7 |
| 02:00 | `secondline:queue-evidence-disposal` | TC-13-08 |
| 02:30 | `atheris:generate-obligation-instances` | |
| 02:45 | `atheris:refresh-vendor-risk` | |
| 03:00 | `atheris:refresh-risk-posture` | TC-05-03 |
| 03:15 | `atheris:refresh-sustainability` | |
| 03:30 | `atheris:evaluate-metrics` | |
| 04:00 | `atheris:sync-data-sources` | TC-18-08 |
| 04:00 | `atheris:roll-up-objectives` | |
| 04:30 | `atheris:run-monitoring-rules --no-capture` | |
| 05:15 | `atheris:refresh-assurance` | |
| 05:30 | `atheris:purge-snapshots` | |
| 05:45 | `ai:reindex` | |
| 06:00 | `atheris:poll-regulatory-feeds` | |
| **07:00** | **`secondline:run-escalations`** | **TC-12-02, -03, -06** |
| Mon 07:30 | `secondline:send-owner-digests` | TC-16-04 |
| Mon 08:00 | `atheris:remind-document-reviews` | |
| Hourly | `atheris:refresh-governance-clocks` | 24h complaint / 72h breach windows |
| Every 15 min | `reports:run-scheduled` | TC-14-09 — carries its own cron **and its own timezone** per schedule |

**Testing consequence.** Escalation is a **daily 07:00 batch**, not event-driven. TC-12-02/-03 cannot be tested by waiting; they require freezing/advancing system time and invoking the command. TC-12-06 (duplicate prevention) is tested by running `secondline:run-escalations` twice and asserting a single `escalation_events` row and a single notification.

**Queue exposure is narrow.** Only one queued job class exists (`IndexKnowledgeRecord`). Escalations and notifications dispatch through `NotificationDispatcher`; whether they are queued determines how TC-12-07 (worker down → recovery) is executed. **To confirm in Phase 1** before writing that case.

**Notifications (14):** `EscalationNotification`, `OwnerDigestNotification`, `ObligationReminderNotification`, `ReportReadyNotification`, `DocumentReviewDueNotification`, `RegulatoryChangeNotification`, `GovernanceClockNotification`, `SubmissionActionNotification`, `DataSourceFailedNotification`, `MfaEmailOtpNotification`, `MfaPolicyChangedNotification`, `SsoConfigChangedNotification`, `BreakGlassLoginNotification`, `PreferenceRoutedNotification`.

---

## 8. Integrations

### 8.1 ThirdLine Internal Audit (BRD Module 11) — **implemented**

Configured per tenant in `integration_configs`: `target_system` ∈ {ThirdLine, NexusRisk}, `master_system` ∈ {SecondLine, ThirdLine, PerCategory}, plus `base_url` and API key. UI at `admin/integrations` (`resources/js/Pages/Admin/Integrations.jsx`).

**Outbound** — `App\Services\IntegrationService`:
- `publishControl()` — on control **approval** (`ControlService::approve`, FR-11.1). Failures log; never block.
- `publishTestResult()` — on test **sign-off** (`TestingService::review`, FR-11.2).
- `publishException()` — on **verified closure** (`ExceptionService::verifyAndClose`).

**Inbound** — `/api/v1`, authenticated by `integration.auth` (`X-Api-Key` header):

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/v1/controls` | Read control definitions |
| POST | `/api/v1/controls` | **Inbound upsert from ThirdLine (FR-11.3)** — sets `sync_status = synced_from_thirdline` |
| GET | `/api/v1/test-results` | |
| GET | `/api/v1/exceptions` | |
| GET | `/api/v1/frameworks/coverage` | Phase 8 compliance surface |
| GET | `/api/v1/obligations/instances` | |
| GET | `/api/v1/assurance/coverage` | Phase 17.5 internal-audit interlock |
| GET | `/api/v1/assurance/gaps` | |
| POST | `/api/v1/assurance/activities` | Reliance decisions write in |
| POST | `/api/v1/assurance/findings` | **An audit finding becomes an ordinary control exception with the ordinary lifecycle** |
| GET | `/api/v1/third-parties` | |

`integration_sync_logs` records direction, payload reference, status and error (FR-11.7). OpenAPI spec present at `docs/openapi.yaml` (FR-11.10).

**Note for TC-18-07:** authentication is signed API key only. BRD FR-11.6 also names *OAuth 2.0 client credentials*. Not observed — see §10, gap **B-04**.

### 8.2 Data-source connectors (Phase 12, not in BRD v1.0)

13 connectors across 6 families: Core Banking (BankOne, Finacle, Flexcube, T24), ERP (SAP, Dynamics 365, Sage), Generic (REST, SQL, SFTP-CSV, Manual Upload), Microsoft Graph, Payments (NIBSS), Tax (FIRS MBS). Each carries a `Capability` / `DatasetDescriptor` contract. Registered via `ConnectorRegistry`, driven by `ConnectorManager`.

---

## 9. Forms and validation surface

59 Form Request classes; 130 Inertia pages; 78 components using `useForm`.

Applying the Part B §12 matrix (31 checks) to every form is **~2,400 checks**. That is not achievable at full depth in a single pass and is the principal scoping decision for Phase 2 (see §11).

Proposed prioritisation for full-depth §12 treatment — the forms where a validation failure is a *control* failure:
1. `ExceptionRequest` — exception raise/update
2. Exception close / verify (verification method + notes)
3. `ControlRequest` — control definition
4. `TestScriptRequest` / check-item builder
5. Test execution + submission (sampling data)
6. Evidence upload (PII classification, retention, file type)
7. `SpotCheckRequest` / `FindingRequest`
8. Effectiveness rating (design, operating, rationale)
9. `RiskRequest` + control-to-risk mapping
10. User create/edit + role assignment

All remaining forms get a reduced matrix: empty submit, server-side bypass (§12.B.13), mass assignment (§12.C.17), XSS (§12.C.14), and DB persistence check (§12.E.29).

### New test cases arising from discovery (not in Part B)

| ID | Test | Why |
|---|---|---|
| TC-New-01 | `webhooks/*` CSRF exemption — replay and forge a webhook without a valid HMAC/token | CSRF is disabled on this path; the signature is the only control |
| TC-New-02 | `auth/sso/*/acs` — replay a captured SAML assertion; submit one with an invalid signature | CSRF disabled; `SamlService` replay protection is the only control |
| TC-New-03 | `audit_trails` immutability via query builder / raw SQL, not just the model | Immutability is model-layer only |
| TC-New-04 | Every `withoutGlobalScopes()` call site — attempt cross-tenant read | Deliberately bypasses tenant isolation |
| TC-New-05 | `Risk Accepted` → `In Progress` via expiry sweep — confirm logged, and that no other path mutates a terminal state | Terminal state is scheduler-mutable |
| TC-New-06 | Evidence upload against a link type **not** in `EvidenceService::LINKABLE` (incident, vendor assessment, monitoring finding) | Only 4 link types supported; BRD FR-9.1 implies broader coverage |
| TC-New-07 | `ExceptionService::verifyAndClose` reached without `ExceptionPolicy` — owner-is-verifier guard | See G-01 |
| TC-New-08 | Ageing refresh uses `updateQuietly()` — confirm absence of audit entries is intended | FR-12.4 requires every update logged |

---

## 10. Reconciliation against BRD v1.0

### 10.1 Reconciliation findings that change the test plan

> **R-01 — CRITICAL TO THE TEST PLAN. The Part B §3 role matrix is wrong about who may close an exception.**
>
> Part B §3 states `control.officer@test.local` (Control Officer) is *"the only role permitted to close exceptions"*.
>
> The implementation reserves closure for **Control Function Head** — `ExceptionPolicy::close()` requires `hasRole('Control Function Head')`, and `ExceptionService::verifyAndClose()` independently re-checks it. Control Officer holds neither `close exceptions` nor the role.
>
> BRD FR-5.4 says *"only the control function"* without naming a role, and the implemented RBAC reads Control Function Head as the control function, with Control Officer as its operator tier. The implementation is consistent with the BRD; **the test plan's role matrix is not.**
>
> **Consequence if unaddressed:** TC-10-05 through TC-10-09 would be executed against the wrong expected result and would log a false Critical (or, worse, mask a real one). The role matrix must be corrected to name Control Function Head as the closing role, with Control Officer added to the *unauthorised* set, before Phase 2 begins.

> **R-02 — The system under test is materially larger than the plan's scope.**
>
> BRD v1.0 defines 12 modules / 106 FRs. Part B §1 scopes 19 modules. The build spans **17 delivery phases** and roughly **35 domains**. Everything below is implemented and reachable but appears in *neither* the BRD nor the test plan:
>
> Regulatory obligations & frameworks · content packs · control distribution · CSA campaigns & surveys · attestations · document management · improvement actions · risk appetite & KRI metrics · risk treatments · policy management · policy exceptions · incident management · complaints · whistleblowing (`speak-up`) · investigation cases · continuous controls monitoring · data-source connectors · segregation-of-duties analysis · dashboard builder · report designer · regulatory submission packs · AI layer (Atlas, governance, budget, prompt library) · mobile/offline/PWA · omnichannel messaging (WhatsApp/SMS/USSD) · multi-entity & consolidation · data residency & cross-border transfers · SoA · strategy/objectives/initiatives · third-party (vendor) risk · sustainability (IFRS S1/S2) · combined assurance.
>
> Each carries its own routes, policies, forms and state machines. Testing them to the Part B depth is a substantially larger engagement than the plan sizes. **A scope decision is required** — see §11.

### 10.2 BRD requirements with no observed implementation

| Ref | Requirement | Observation |
|---|---|---|
| **B-01** | FR-2.6 / FR-11.8 — consume risk register from **NexusRisk IRM** by API | `NexusRisk` is a valid `target_system` enum value and appears in the UI, but no consuming client was found. *Should*-priority. |
| **B-02** | FR-11.9 — **webhook subscriptions** so ThirdLine is notified in near real time of new Critical/High exceptions | Outbound publish fires on *closure*, not on raise. Inbound `webhooks/*` routes exist (Phase 15 messaging), but no outbound subscription mechanism observed. *Should*-priority. |
| **B-03** | FR-9.10 — **redaction of evidence in place**, retaining the unredacted original under stricter access | Not observed in `EvidenceService`. *Should*-priority. |
| **B-04** | FR-11.6 — **OAuth 2.0 client credentials** as an integration auth option | Only signed API key (`X-Api-Key`) observed. FR-11.6 is *Must* and offers OAuth **or** signed keys — arguably satisfied, but the OAuth half is absent. To confirm in Phase 2. |
| **B-05** | FR-12.2 — **custom role definition** | Six seeded roles; a role administration UI exists (`RoleAdministrationTest`). Whether tenants can define *new* roles rather than edit existing ones is to be confirmed in Phase 2. |
| **B-06** | FR-9.11 — **data subject request handling** — locate personal data in evidence for a named individual | PII classification and retention are implemented; a DSR search path was not observed. *Should*-priority. NDPA-relevant. |

*All six are flagged for confirmation during Phase 2 execution, not asserted as defects. Absence from discovery is not proof of absence from the build.*

### 10.3 Implemented but not in BRD v1.0 — direct contradiction

| Ref | Observation |
|---|---|
| **B-07** | **FR-10.9 states: "Keep the dashboard deliberately simple — no configurable widget builder in Version 1."** The build ships `DashboardBuilderController`, `DashboardBuilderService`, `Dashboard` + `DashboardWidget` models, `manage dashboards` / `publish dashboards` permissions, and a `report-designer` surface. Every role including Control Owner and Executive Viewer holds `manage dashboards`. This directly contradicts a *Must* requirement. Presumed sanctioned by a later phase, but **BRD v1.0 has not been updated to record the decision** — which is exactly the kind of undocumented scope change an examiner would query. Raise with the Product Owner; do not test against FR-10.9 as written. |

Everything else in §10.1/R-02 is additive rather than contradictory.

---

## 11. Decisions — settled 2026-08-14

| # | Decision | Ruling |
|---|---|---|
| **D-1** | **Database for the live environment** (blocker E-01) | **Product owner is standing MySQL up.** The live environment will run on MySQL, matching production — no SQLite divergence to record. Browser/API/report/escalation testing resumes once the server is reachable. The PHPUnit suite continues to run on in-memory SQLite per the project's existing `phpunit.xml` convention. |
| **D-2** | **Exception-closure role** (finding R-01) | **The test plan is corrected; the implementation stands.** *The control function* in BRD FR-5.4 is the **Control Function Head** role. Part B §3's role matrix is amended accordingly — see §11.1. |
| **D-3** | **Scope** (finding R-02) | **BRD modules deep, remainder smoke.** Full Part B depth across the 12 BRD v1.0 modules; authorisation, state-machine and smoke coverage across the other ~20 domains. Reduced depth on the non-BRD domains is a deliberate, recorded scope decision, not a coverage gap — but it will be restated in `04-coverage-gaps.md` so the release recommendation is read with it in view. |
| **D-4** | **FR-10.9 contradiction** (B-07) | **Open — Product Owner to rule.** Not blocking. FR-10.9 will be marked *Superseded — pending PO confirmation* in the traceability matrix rather than tested as written or silently passed. |

### 11.1 Amended role matrix (supersedes Part B §3)

The single change is to who may close an exception. Everything else in Part B §3 stands.

| Test user | Role | Boundary |
|---|---|---|
| `admin@secondline.test` | System Administrator | Full configuration. **Must not close exceptions** — no SysAdmin bypass exists in `ExceptionPolicy`, by design. Sole holder of `view all cases`. |
| `cfh@secondline.test` | **Control Function Head** | **The control function. The only role permitted to close an exception** — and only from `Remediated`, only after recording a verification method, never on a test it personally performed, never on a control it owns. |
| `officer1@secondline.test` | Control Officer | Defines controls, executes tests, raises and remediates exceptions. **Now in the *unauthorised* set for closure** (TC-10-05 … -09). |
| `owner1@secondline.test` | Control Owner | Remediates and uploads evidence. Cannot close. Cannot self-close. |
| `manager@secondline.test` | Line Manager | Escalation tier 1. Read-across; authors nothing. |
| `exec@secondline.test` | Executive Viewer | Escalation tier 2 / board. Read-only + `approve appetite`, `approve objectives`. |
| *(to create)* | Deactivated user | Must be denied at login and on API token. |

**Closure negative set for TC-10-05 … -09:** System Administrator, Control Officer, Control Owner, Line Manager, Executive Viewer — each tested both through the UI and by direct endpoint call with a valid session.

### 11.2 Scope tiers under D-3

**Tier 1 — full Part B depth** (BRD v1.0 modules): authentication & session · users/roles/permissions · organisation & hierarchy · control library · risk & RCSA mapping · checklists · control testing · effectiveness ratings · spot checks · exception management · compensating controls · escalation engine · evidence & NDPA · reporting · dashboard · notifications · audit trail · ThirdLine integration · non-functional.

**Tier 2 — smoke + authorisation + state machine** (post-BRD domains): regulatory obligations & frameworks · content packs · control distribution · CSA campaigns & surveys · attestations · documents · improvement actions · risk appetite & KRI metrics · risk treatments · policies & policy exceptions · incidents · complaints · whistleblowing · investigation cases · continuous monitoring · data-source connectors · SoD analysis · dashboard builder · report designer · submission packs · AI layer · mobile/offline/PWA · omnichannel messaging · multi-entity & residency · SoA · strategy/objectives · vendor risk · sustainability · combined assurance.

Tier 2 per domain: list view loads · create/update happy path · **every restricted action called directly as ≥2 unauthorised roles** · **every illegal state transition attempted via API** · tenant isolation probe.

---

## 12. Phase 2 execution order (proposed)

Sequenced so that the highest-regulatory-risk areas are proven first and a blocker there stops the run early rather than late.

1. **TC-01** Authentication & session · **TC-02** Users, roles & permissions — including every direct-endpoint negative
2. **TC-10** Exception management — the closure control (TC-10-05 … -09) first, both UI and API
3. **TC-17** Audit trail — including TC-New-03
4. **TC-07 / TC-08** Control testing and effectiveness ratings, with the full rating matrix
5. **TC-04 / TC-05** Control library, risk mapping, residual-risk recomputation
6. **TC-12** Escalation engine — time-frozen
7. **TC-13** Evidence, retention, NDPA
8. **TC-15 / TC-14** Dashboard and report reconciliation to SQL
9. **TC-18** ThirdLine integration, both directions + failure/retry
10. **TC-03 / TC-06 / TC-09 / TC-11 / TC-16** Organisation, checklists, spot checks, compensating controls, notifications
11. **§12 form matrix** — full depth on the ten priority forms, reduced on the rest
12. **§7–§10** Workflow, data integrity, API suite, security
13. **§11–§12** Performance, compatibility, responsive, accessibility
14. Post-BRD domains — smoke + authorisation + state machine (subject to D-3)

---

## 13. Appendices

- [`evidence/00-route-table.tsv`](evidence/00-route-table.tsv) — all 502 routes: verb, URI, name, handler, middleware chain
- `database/seeders/RolePermissionSeeder.php` — authoritative role → permission mapping
- `docs/openapi.yaml` — published integration specification (FR-11.10)
- `Control-Solution-BRD-v1.0.md` — requirements source for `03-traceability-matrix.md`
