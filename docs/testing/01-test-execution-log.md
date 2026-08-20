# 01 — Test Execution Log

**Product:** SecondLine — Atheris Control Solution
**Branch / commit:** `phase-17-extended-grc` @ `8d233c7`
**Run date:** 2026-08-14
**Executed by:** QA Automation / Test Analysis

> **Read this first.** This log records what was **actually executed**, with evidence. It is not a plan. Test cases from Part B that do not appear here were not run — they are listed in [`04-coverage-gaps.md`](04-coverage-gaps.md), not silently omitted. The run is **incomplete**: see [`05-summary-report.md`](05-summary-report.md) for the release position.

---

## Environment

| Item | Value |
|---|---|
| Application | `http://127.0.0.1:8123` (`php artisan serve`) |
| Database engine | **MariaDB 10.4.28** |
| Database | `atheris_control_e2e` — created fresh, isolated from the developer's `thirdLine-control` schema, which was left untouched |
| Migrations | All **161** applied from zero, no errors |
| Seed | `DatabaseSeeder` (full product seed) + `E2ETestSeeder` (test fixtures) |
| `APP_ENV` | `e2e` for the served application (`.env.e2e`); `testing` for the PHPUnit e2e suite |
| `APP_DEBUG` | `false` |
| Queue | `database` driver, `php artisan queue:work` running and monitored |
| Scheduler | Not run as a daemon. Scheduled commands invoked directly — see note below |
| Mail | `log` driver for the served app; `array` driver in the test suite |
| Frontend | Pre-built Vite assets present in `public/build` |

**Engine note.** The stack targets MySQL; the available server is **MariaDB 10.4.28**. All 161 migrations applied cleanly and enum constraints are enforced (verified incidentally — an invalid `auth_type` value was rejected at the storage layer during fixture seeding). MariaDB 10.4 nonetheless differs from MySQL 8 in JSON storage (`LONGTEXT` alias), collation defaults and functional-index support. Recorded as a coverage gap, not a defect.

**Scheduler note.** Escalation is a daily 07:00 batch (`secondline:run-escalations`), not event-driven. Time-frozen testing therefore requires invoking the commands against a manipulated clock; that harness now exists (see *Test harness* below) and is proven against two commands, but **TC-12 itself has not yet been executed**.

### Reproducing this environment

```bash
php artisan tinker --execute="DB::statement('CREATE DATABASE IF NOT EXISTS \`atheris_control_e2e\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"
```

```bash
DB_DATABASE=atheris_control_e2e php artisan migrate:fresh --force && DB_DATABASE=atheris_control_e2e php artisan db:seed --force && DB_DATABASE=atheris_control_e2e php artisan db:seed --class=E2ETestSeeder --force
```

```bash
./vendor/bin/phpunit -c phpunit-e2e.xml --testdox
```

### Seeded fixtures

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| users | 9 (8 product + 1 deactivated) | | control_exceptions | 17 |
| roles | 6 | | test_instances | 10 |
| permissions | 159 | | check_results | 9 |
| tenants | 1 | | effectiveness_ratings | 2 |
| organisation_units | 10 | | escalation_matrices | 30 |
| controls | 37 | | escalation_events | 3 |
| risks | 7 | | audit_trails | 2,156 |
| test_scripts | 4 | | rating_matrix_entries | 16 |
| check_items | 10 | | integration_configs | 1 (ThirdLine, added by E2ETestSeeder) |

**Fixture gaps found in the product seed — since closed.** `spot_checks`, `findings`, `compensating_controls` and `evidence` all seed at **zero rows** from `DatabaseSeeder` alone, and only one tenant exists. TC-09, TC-11, TC-13 and every isolation test were blocked as a result. The extended `E2ETestSeeder` (see *Test harness* below) now supplies all of them:

| Table | Rows after `E2ETestSeeder` |
|---|---|
| spot_checks | 4 — one at each lifecycle state (Draft / In Progress / Completed / Report Issued) |
| findings | 6 — across the three post-Draft spot checks, mixed severity |
| compensating_controls | 3 — Proposed, Active-permanent, and Active-temporary already past its end date |
| evidence | 4 — clean, PII-classified, legal-hold-past-expiry, and expired-no-hold; real files on disk with matching SHA-256 checksums |
| tenants | 2 — the second holds its own users, unit, control and exception, all marked *MUST NEVER BE VISIBLE TO TENANT 1* |

---

## Results — executed cases

Status key: **Pass** · **Fail** · **Blocked** · **N/A**

### TC-10 — Exception management (closure control) — BRD REQ-001 / FR-5.4, FR-5.5

Automated: `tests/e2e/ExceptionClosureControlTest.php` — 24 tests, 181 assertions, **22 Pass / 1 Fail / 1 Skipped (intentional)**.

Every negative was executed by calling the endpoint directly with a valid session for the wrong role, and by re-reading the row from the database afterwards to prove no state change occurred.

| ID | Test | Role | Expected | Actual | Status |
|---|---|---|---|---|---|
| TC-10-04 | Owner submits remediation | Control Owner | → `Remediated`, not Closed; `verified_*` stay null | As expected | **Pass** |
| TC-10-05 | Control Owner attempts close (direct POST) | Control Owner | 403, no state change | 403, status unchanged | **Pass** |
| TC-10-05 | Control Officer attempts close (per R-01) | Control Officer | 403, no state change | 403, status unchanged | **Pass** |
| TC-10-06 | Administrator attempts close (direct POST) | System Administrator | 403, no state change | 403, status unchanged | **Pass** |
| TC-10-06 | Line Manager attempts close | Line Manager | 403 | 403 | **Pass** |
| TC-10-06 | Executive Viewer attempts close | Executive Viewer | 403 | 403 | **Pass** |
| TC-10-07 | CFH closes from `Open` | Control Function Head | Blocked — verification first | 403, unchanged | **Pass** |
| TC-10-07 | CFH closes from `Assigned` | Control Function Head | Blocked | 403, unchanged | **Pass** |
| TC-10-07 | CFH closes from `In Progress` | Control Function Head | Blocked | 403, unchanged | **Pass** |
| TC-10-08 | CFH verifies then closes | Control Function Head | Allowed; verifier, timestamp, method recorded | `Verified-Closed`; `verified_by`, `verified_at`, `verification_method` all set; overdue cleared | **Pass** |
| TC-10-08 | Close without `verification_method` | Control Function Head | Rejected server-side | Validation error, status unchanged | **Pass** |
| TC-10-09 | Service layer refuses unauthorised closer (×4 roles) | admin, officer, manager, exec | `ValidationException` | Refused for all four | **Pass** |
| TC-10-09 | Service layer refuses exception owner | Control Function Head | `ValidationException` | **Closed the exception** | **Fail — DEF-003** |
| — | CFH cannot close on a control it owns | Control Function Head | 403 | 403 | **Pass** |
| — | CFH cannot close an exception it owns (HTTP) | Control Function Head | 403 | 403 | **Pass** |
| — | Tester of the source test cannot close | Control Function Head | 403 | 403 | **Pass** |
| TC-10-10 | `Verified-Closed` is terminal | CFH / Control Owner | Re-close and re-remediate both refused | Both 403 | **Pass** |
| TC-10-13 | Closed exception retained, not hard-deleted | — | Row survives, `deleted_at` null, leaves open set | As expected | **Pass** |
| TC-10-16 | Closure writes an attributable audit record | Control Function Head | `closed` action, actor attributed, activity thread records `Remediated → Verified-Closed` | As expected | **Pass** |
| TC-10-01 (partial) | UI does not offer closure to unauthorised roles | 5 roles | `can.close` false or page denied | As expected | **Pass** |

**Assessment.** The single highest-risk control in the product — *only the control function may close an exception, and only after verifying remediation* — **holds under direct endpoint attack from every other role**, including System Administrator. The one failure (DEF-003) is a service-layer defence-in-depth gap that is not reachable through any current code path.

### TC-17 — Audit trail

Automated: `tests/e2e/AuditTrailIntegrityTest.php` — 9 tests, 45 assertions, **7 Pass / 2 Fail**.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-17-01 | Update writes audit record with before/after | Captured | `action=updated`, before and after arrays populated | **Pass** |
| TC-17-01 | HTTP-originated change captures actor, IP, agent | Captured | Actor, IP and user-agent all recorded | **Pass** |
| TC-17-02 | Audit record cannot be updated via the model | `LogicException` | Threw | **Pass** |
| TC-17-02 | Audit record cannot be deleted via the model | `LogicException` | Threw | **Pass** |
| TC-17-02 | No route exposes audit mutation | Empty set | Full route table enumerated; no mutating audit route exists | **Pass** |
| TC-New-03 | Query-builder write to `audit_trails` | Rejected | **Row silently rewritten** | **Fail — DEF-004** |
| TC-17-04 | Audit log readable/exportable by authorised roles | 200 | 200 for System Administrator | **Pass** |
| TC-17-04 | Audit log denied to unauthorised roles | 403 | 403 for officer, owner, manager, exec — both view and export | **Pass** |
| TC-17-05 | Denied actions are recorded | A record exists | **No record anywhere** | **Fail — DEF-005** |

### TC-02 — Users, roles & permission enforcement (+ TC-01-02, TC-01-03)

Automated: `tests/e2e/AuthorisationMatrixTest.php` — 11 tests, 180 assertions, **10 Pass / 1 Fail**.

**TC-02-05 executed as a sweep across the whole routing table**, not a hand-picked sample — a sample only tests the routes someone already thought about. For each mutating route declaring a `role:` / `permission:` / `role_or_permission:` requirement, the suite parses the requirement, selects the least-privileged seeded account that fails it, calls the endpoint directly, and asserts the caller is neither admitted (2xx) nor met with a server error (5xx — the plan requires "403, not 200 or 500").

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-02-05 | Sweep: 28 parameterless gated routes, each called by an unauthorised role | No 2xx, no 5xx | **0 admitted, 0 server errors** | **Pass** |
| TC-02-05 | Sweep: parameterised `exceptions/{exception}` routes with a real record id | No 2xx | 0 admitted | **Pass** |
| TC-02-06 | 5 non-admin roles reach role administration | 403 on view and on role update | 403 throughout; no role gained | **Pass** |
| TC-02-06 | Control Officer grants itself Control Function Head | Denied | 403; role not gained | **Pass** |
| TC-02-06 | System Administrator role edited | Immutable | Rejected; retains all permissions | **Pass** |
| — | Seeded BRD §4 roles deleted | Prevented | Rejected; roles intact | **Pass** |
| TC-02-08 | Last active administrator demoted (by themselves) | Prevented | Rejected; retains the role | **Pass** |
| TC-02-02 | Role change takes effect immediately | New granted, old revoked | Both correct — `syncRoles` replaces rather than appends | **Pass** |
| TC-01-03 | Deactivated user logs in | Denied | Denied | **Pass** |
| TC-01-02 | Unknown account vs wrong password | Identical message | Identical — no account enumeration | **Pass** |
| TC-02-03 | Deactivating a user ends their live session | Access ends | **Access continues in full** | **Fail — DEF-007** |

**Sweep coverage, stated honestly.** 43 parameterless gated routes exist; 28 were checked and **15 skipped because every seeded role satisfies their requirement** — those need a purpose-built under-privileged account to test meaningfully. Of the 161 parameterised gated routes, only the `exceptions/{exception}` family was swept, because a sweep needs a real record id per parameter for route-model binding to resolve and the authorisation layer to actually be reached. A further **121 mutating routes declare no role/permission middleware at all**, relying on in-controller policy calls; those were not assessed. All three are recorded in `04-coverage-gaps.md`.

**Assessment.** Authorisation enforcement is strong where it was tested: no unauthorised caller was admitted anywhere in the sweep, privilege escalation is blocked at every attempt, and both roles-screen invariants hold. The single failure is not an authorisation gap but a **session-lifecycle** one — deactivation does not reach a session already in flight.

### TC-02-07 / TC-New-04 — Tenant isolation and IDOR

Automated: `tests/e2e/TenantIsolationTest.php` — 9 tests, 61 assertions, **8 Pass / 1 Fail**.

Fixtures are two tenants whose records are named *"MUST NEVER BE VISIBLE TO TENANT 1"*, so a leak is unmistakable in the failure output. Isolation is tested **in both directions** — testing one direction proves the scope filters *a* tenant, not that it filters correctly.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-02-07 | 6 roles open another tenant's exception by URL | Denied, no content leak | Denied for all six | **Pass** |
| TC-02-07 | 4 roles open another tenant's control by URL | Denied | Denied | **Pass** |
| TC-02-07 | Other tenant's CFH opens *our* exception | Denied | Denied | **Pass** |
| TC-02-07 | Our CFH closes another tenant's `Remediated` exception | No state change | Status unchanged | **Pass** |
| TC-02-07 | Our CFH renames another tenant's control | No change | Title unchanged | **Pass** |
| TC-New-04 | Index listings (exceptions, controls, risks, dashboard) × 3 roles | No foreign records | No leak in any of 12 combinations | **Pass** |
| TC-02-07 | `/api/v1/exceptions` and `/api/v1/controls` with tenant 1's key | Scoped to tenant 1 | Scoped correctly | **Pass** |
| — | `/api/v1` with missing / wrong key | 401 | 401 both | **Pass** |
| TC-02-07 | Assign our exception to a **user from another tenant** (payload, not URL) | Rejected | **Accepted** | **Fail — DEF-008** |
| — | Does that assignment grant the foreign user read access? | No | No — tenant scope holds | **Pass** |

**Assessment.** URL-based IDOR is solidly closed, in both directions, on both the web and integration surfaces. The one gap is **payload** IDOR: a foreign user id passed in a request body is accepted because `exists:users,id` validates existence but not tenancy, and `App\Models\User` — alone among the domain models — carries no `BelongsToTenant` scope. The follow-on test establishes that this does **not** become a cross-tenant breach: the global scope on `ControlException` still blocks the read.

### TC-02-05 (second half) — Mutating routes with no role/permission middleware

Automated: `tests/e2e/UngatedRouteAuthorisationTest.php` — 5 tests, 16 assertions, **4 Pass / 1 Fail**.

121 mutating routes declare no role/permission middleware and depend entirely on an in-controller check. Each was triaged by reflecting on its handler source for `$this->authorize()`, `Gate::`, `->can()`, `abort_if/unless()` — **and** on any `FormRequest::authorize()` body, since that is an equally valid place for the check.

| Population | Count |
|---|---|
| Un-gated mutating routes | 121 |
| …with an inline or FormRequest authorisation check | 96 |
| …with **no** check found | **25** |

All 25 were reviewed individually. 24 are correct: machine callers with their own authentication (`/api/v1/*` behind `integration.auth`, `webhooks/*` behind signatures), endpoints unauthenticated by design (`login`, `logout`, anonymous whistleblowing), and endpoints acting only on the caller's own records (MFA enrolment, own notifications, own push subscription, own saved view, own display preference).

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-02-05 | No **unreviewed** route mutates without a check | Empty set | Empty — all 25 accounted for | **Pass** |
| — | Reviewed list contains no stale entries | Empty set | Empty | **Pass** |
| TC-13-06 | Executive Viewer and Line Manager attach evidence to a control test | Refused | **Both succeeded** | **Fail — DEF-009** |
| — | Evidence attached to another tenant's record | Refused | Refused | **Pass** |
| — | One user hijacks another's chunked upload (append / complete / abort) | 404 on all three | 404 on all three | **Pass** |

The static scan produced two classes of false positive, both corrected rather than allowlisted: routes whose check lives in a private helper (`ChunkedUploadController::ownUpload()`, confirmed clean by the dynamic hijack test), and routes whose check lives in a `FormRequest::authorize()` (`LinkageController@store` requires `manage linkage`; notification preferences are self-scoped). The scan now inspects FormRequests, and both tests are retained as **regression guards** — a newly added un-gated route without a check will fail the build.

### TC-13 — Evidence management, retention & NDPA (FR-9.1 … FR-9.11)

Automated: `tests/e2e/EvidenceRetentionTest.php` — 27 tests, 122 assertions, **19 Pass / 8 Fail** (8 failures across 2 defects).

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-13-01 | Upload CSV / PDF / PNG / XLSX | Stored, checksummed, retention set, retrievable | All four correct | **Pass** |
| TC-13-02 | `.php`, `.exe`, `.sh`, `.pdf.php`, spoofed MIME, `.svg`, `.html` | Rejected server-side | **All seven accepted** | **Fail — DEF-010** |
| TC-13-02 | Evidence disk is outside the document root and has no public URL | Not web-served | Confirmed: `storage_path('app/private')`, no `url` | **Pass** |
| — | Downloads served as `Content-Disposition: attachment` | Attachment | Attachment | **Pass** |
| TC-13-03 | 20 MB + 1 KB file | Rejected | Rejected with a field error | **Pass** |
| TC-13-04 | Zero-byte file | Rejected | **Accepted** | **Fail — DEF-011** |
| TC-13-05 | Storage paths not guessable | Randomised, no filename echo | `evidence/YYYY/MM/<40-char hash>` | **Pass** |
| TC-13-05 | Download permission-checked | admin/CFH/officer allowed; owner/manager/exec denied | Exactly that | **Pass** |
| TC-13-05 | Another tenant downloads our evidence | Denied | Denied | **Pass** |
| FR-9.6 | Every download access-logged | Logged | Logged | **Pass** |
| TC-13-09 | Upload without the PII declaration | Rejected | Rejected | **Pass** |
| TC-13-09 | PII declared without categories | Rejected | Rejected | **Pass** |
| TC-13-07 | PII evidence takes the Customer Personal Data retention class | 60-month class applied | Applied, expiry derived from policy | **Pass** |
| TC-13-08 | Expired evidence queued; legal hold excluded | Hold suspends disposal | Held item untouched; free item queued | **Pass** |
| TC-13-08 | Same user gives both disposal approvals | Refused | Refused | **Pass** |
| TC-13-08 | Dual-approved disposal | File deleted, `disposed` audit entry, record survives, download → 410 | All four correct | **Pass** |
| FR-9.7 | Legal hold set by a non-control-function role | Denied | Denied for officer/owner/manager/exec | **Pass** |
| TC-13-11 | Checksum detects substitution | Detectable | Detectable | **Pass** |

**Assessment.** The retention, NDPA and disposal chain is the strongest module tested so far, and it is worth saying so plainly: the mandatory PII declaration, the PII-specific retention class, legal hold suspending disposal, genuine two-person disposal approval that refuses the same approver twice, file deletion with the audit record and the evidence row both surviving, `410 Gone` on a disposed item, unguessable storage paths, permission-checked and access-logged downloads, and tenant isolation on download — all hold.

Both failures are in one place: **upload-time file validation**, which is `['required', 'file', 'max:20480']` and nothing else. The plan's Critical condition for TC-13-02 — *"a web-executable file lands in a web-served path"* — was tested and is **not** met, which is what keeps DEF-010 at Medium.

**TC-13-06 (evidence deletion) is N/A as specified.** There is no evidence-delete route; removal happens only through the retention/disposal pipeline, which is tested above. That is a stronger design than the test case assumes.

**TC-13-10 (encryption at rest / in transit) not executed.** Storage driver confirmed as local-private, but disk-level encryption and TLS configuration are deployment concerns not observable from this environment.

### TC-07 — Control testing execution (FR-3.1 … FR-3.10)

Automated: `tests/e2e/ControlTestingTest.php` — 20 tests, 78 assertions, **19 Pass / 1 Fail**.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-07-01 | Period boundaries for Daily, Weekly, Monthly, Quarterly, Semi-annual, Annual | Correct start and end per frequency | All six exact | **Pass** |
| TC-07-01 | Second generation run for the same period | No duplicate | None | **Pass** |
| TC-07-11 | Retired control | Not schedulable | Not scheduled | **Pass** |
| — | Event-driven control | Not auto-scheduled | Not scheduled | **Pass** |
| TC-07-01 | Generated instance carries an assigned tester (FR-3.4) | Tester set | **`null`** | **Fail — DEF-013** |
| TC-07-03 | All check items Pass | Effective, no exception raised | No exception | **Pass** |
| TC-07-04 | Check items Fail | One exception per failed item, linked to source | Exactly that; severity from the check item default | **Pass** |
| FR-3.2 | Fail without a comment | Rejected | Rejected | **Pass** |
| — | Submit with a mandatory item unanswered | Blocked | Blocked | **Pass** |
| TC-07-10 | Reviewer rejects | Returns to tester with notes | `In Progress` + notes | **Pass** |
| TC-07-10 | Reviewer approves | Locks the record | `Reviewed`, locked | **Pass** |
| TC-07-09 | Edit a locked test | Blocked | Blocked | **Pass** |
| FR-12.3 | Tester reviews their own test | Refused | Refused | **Pass** |
| — | Review a test that is not Submitted | Refused | Refused | **Pass** |
| FR-3.9 | Reopen a locked test | Requires a reason, audited | Reason stored, `reopened` audit entry written | **Pass** |
| — | Reopen an unlocked test | Refused | Refused | **Pass** |
| TC-07-08 | Overdue flagging | Flagged; a Reviewed test leaves the set | Both correct | **Pass** |

**Not run:** TC-07-05 (draft save/resume), TC-07-06 and TC-07-07 (evidence attached during a test, and submission blocked without required evidence — the mandatory-evidence gate itself was not located).

### TC-08 — Control effectiveness ratings (FR-7.1 … FR-7.8)

Automated: `tests/e2e/EffectivenessRatingTest.php` — 33 tests, 108 assertions, **32 Pass / 1 Fail**.

**TC-08-03 was executed as the full matrix, not a sample** — all sixteen design × operating combinations asserted individually, plus three properties that hold whatever the matrix is reconfigured to say.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-08-03 | All **16** matrix cells | Resolve as configured | All 16 correct | **Pass** |
| TC-08-03 | Design Effective + Operating Ineffective | **Not** overall Effective | `Ineffective` | **Pass** |
| FR-7.4 | Design Ineffective, any operating value | Never better than Partially Effective | Always `Ineffective` | **Pass** |
| — | No cell rates better than its weakest dimension | Property holds | Holds across all 16 | **Pass** |
| — | Matrix completeness | No combination falls through to the `'Not Tested'` default | All 16 present | **Pass** |
| TC-08-01/-02 | Design and operating stored separately | Two attributes + derived overall | Correct | **Pass** |
| TC-08-04 | Non-Effective rating without a rationale | Rejected (both dimensions) | Rejected | **Pass** |
| — | Effective rating without a rationale | Allowed | Allowed | **Pass** |
| FR-7.6 | New rating | `Pending Approval`, no approver | Correct | **Pass** |
| FR-7.6 | Rater approves their own rating | Refused | Refused | **Pass** |
| FR-7.6 | Second person approves | Published, approver + timestamp recorded | Correct | **Pass** |
| FR-7.8 | Approval recomputes residual risk | Recomputed, within inherent and above the 20% floor | Correct | **Pass** |
| **FR-2.4** | **Residual risk reconciles to an independent recomputation** | Stored = recomputed, every risk | **Exact match across all risks** | **Pass** |
| TC-08-05 | At most one rating per control per period | Trend well defined | Correct | **Pass** |
| — | Re-rating the same instance | Updates, does not duplicate | One row | **Pass** |
| — | Re-rating a published rating | Returns to `Pending Approval` | Correct | **Pass** |
| — | Re-rating clears the previous approver | `approved_by` cleared | **Stale approver retained** | **Fail — DEF-014** |

**Assessment.** The rating engine is correct. The 16-cell matrix resolves exactly as configured, the FR-7.4 rule holds as a property rather than by luck of the seed, rationale is enforced on both dimensions, and the maker–checker gate on publication works including the same-person refusal.

**FR-2.4 is the strongest single result in the run.** The plan requires every calculation to be independently recomputed from the database and compared. The residual-risk formula — `max(inherent × (1 − weighted mean reduction), inherent × 0.2)`, with Effective 0.5 / Partially Effective 0.25 / otherwise 0, plus a 0.15 compensating-control bonus capped at 0.5 — was reimplemented in the test and **reconciles exactly, for every risk**.

**Seed-data observation (not a defect).** Two risks carry a residual rating below their inherent rating while having **no mapped controls at all** — a state the engine cannot produce. Both are literal seeder values: `Phase16DemoSeeder:224` writes `residual_rating => 8` and `Phase17DemoSeeder:524` writes `12`. The engine corrects them as soon as it runs. Worth raising with the team because a demo shown to a client would display a risk reduction that no control produced.

### Tier 2 smoke pass — the ~20 post-BRD domains (scope decision D-3)

Automated: `tests/e2e/Tier2SmokeTest.php` — 27 tests, 63 assertions, **25 Pass / 1 Fail / 1 Skipped**.

Every check is derived from the routing table rather than a hand-written list, so a domain added later is covered without anyone remembering to add it. **105 authenticated pages** across obligations, frameworks, content packs, CSA, attestations, documents, improvements, appetite, metrics, treatments, policies, incidents, complaints, whistleblowing, cases, monitoring, connectors, SoD, dashboards, report designer, submissions, AI, mobile/offline, messaging, entities, residency, SoA, strategy, vendors, sustainability and assurance.

| Check | Coverage | Result | Status |
|---|---|---|---|
| No page returns a server error | 105 pages × 6 roles = **630 page loads** | **Zero 5xx** | **Pass** |
| A non-qualifying role is never admitted | Every gated page × every role that fails its requirement | **None admitted** | **Pass** |
| Policy stricter than route refuses cleanly | Every page a role qualifies for | Clean 403s only; no server error, no partial render | **Pass** |
| No page leaks another tenant's records | 105 pages × 6 roles | **No leak anywhere** | **Pass** |
| The other tenant sees none of ours | 105 pages | No leak | **Pass** |
| Disabling a feature flag closes its module | `vendors` | Module closed | **Pass** |
| Deactivated user is denied | 105 pages | **Reaches 11 pages across 9 domains** | **Fail — DEF-007** |

**Assessment.** Across 630 authenticated page loads there was **not one server error and not one cross-tenant leak**, and no role was ever admitted to a page it did not qualify for. For a surface this size — 105 pages over ~30 domains, most of them outside the BRD and none of them previously exercised — that is a strong result.

**A divergence worth recording, which is not a defect.** Nine pages carry no role/permission middleware and are gated entirely by an in-controller `authorize()` call: `controls/create`, `exceptions/create`, `frameworks/create`, `obligations/create`, `policies/create`, `csa-campaigns/create`, `incidents/create`, `links/candidates` and `cases/board-extract`. The policy layer is therefore **stricter** than the routing table — the safe direction. The clearest case is the System Administrator being refused `exceptions/create`, exactly consistent with the deliberate absence of an admin bypass in `ExceptionPolicy`. My first version of this test asserted the wrong invariant (that a route-qualifying role is always admitted) and reported five false failures; it now asserts the property that actually matters — a refusal is a clean 403, never a 500 or a half-render — with the divergence written out to `evidence/tier2-policy-stricter-than-route.txt` for review.

### Part B §9 — API test suite (all 11 `/api/v1` endpoints)

Automated: `tests/e2e/ApiContractTest.php` — 65 tests, 158 assertions, **63 Pass / 2 Fail**.

Every check below ran against **all eleven** endpoints, not a sample.

| Check | Result | Status |
|---|---|---|
| Absent key → 401 | 11/11 | **Pass** |
| Invalid key → 401 | 11/11 | **Pass** |
| No stack trace, no `SQLSTATE`, no `APP_KEY`, no echo of the caller's key | 11/11 clean | **Pass** |
| Read endpoints → 200 + JSON structure | 8/8 | **Pass** |
| Unknown query parameter / nonsense `since` value | Ignored, still 200 | **Pass** |
| Validation failure → 422 with `{message, errors}` | Consistent envelope | **Pass** |
| Auth failure → `{message}` envelope | Consistent | **Pass** |
| Wrong types (array for string, bad date, string for object) | All 422, not coerced | **Pass** |
| Empty / malformed body | 4xx, never 5xx | **Pass** |
| Oversized field (100 000 chars) | 422, no server error | **Pass** |
| No read endpoint returns another tenant's records | 8/8 clean | **Pass** |
| Inbound write cannot choose its own tenant | Key determines tenant | **Pass** |
| **70 requests with a valid key → a 429 appears** | **70/70 answered 200** | **Fail — DEF-019** |
| **70 invalid-key attempts → a 429 appears** | **70/70 answered 401** | **Fail — DEF-019** |

### Part B §10 — Application security checks

Automated: `tests/e2e/SecurityBaselineTest.php` — 12 tests, 30 assertions, **11 Pass / 1 Fail**.
Live verification: `evidence/S10-security-live-verification.txt`.

| Check | Result | Status |
|---|---|---|
| Security headers | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, full CSP with `frame-ancestors 'none'` — all present live | **Pass** |
| Cookie flags | Session cookie `httponly; samesite=lax`; `XSRF-TOKEN` correctly JS-readable | **Pass** |
| HSTS | Set conditionally on `$request->secure()` — correct; not exercised (HTTP environment) | **N/A** |
| Verbose errors disabled | Live app returns a plain "Not Found", no stack trace | **Pass** |
| Directory / file exposure | `/vendor`, `/storage`, `/build`, `/.env`, `/.git/config` all 404 | **Pass** |
| Dependency vulnerabilities | `composer audit` — **no advisories** | **Pass** |
| Password hashing | bcrypt; hash never serialised into a page payload | **Pass** |
| Auth rate limiting | 8 failed logins → lockout message (FR-12.8) | **Pass** |
| Account enumeration | Identical message for unknown account and wrong password | **Pass** |
| Session fixation | Session id regenerated on login | **Pass** |
| Logout | Session invalidated; protected page redirects to login | **Pass** |
| Open redirect | 3 payloads (absolute, protocol-relative, backslash) all refused | **Pass** |
| Secrets in the client bundle | None found in any built JS | **Pass** |
| `APP_KEY` set and non-default | Correct | **Pass** |
| Injected filter on an API read | 4xx, no internals leaked | **Pass** |
| **Shipped `.env.example` / deployment guidance** | **`APP_DEBUG=true` shipped, no document mentions it** | **Fail — DEF-020** |
| `X-Powered-By` | `PHP/8.4.18` disclosed on every response | **Fail — DEF-021** |

**Assessment.** The security baseline is largely sound: headers, cookie flags, hashing, login throttling, session fixation, open redirect, secret exposure and dependency posture all hold, and `composer audit` is clean. The two authentication surfaces diverge sharply, though — **interactive login is throttled and the machine API is not at all** (DEF-019), which is the more serious of the two given the API returns up to 500 records per call.

**One observation, not a defect:** the production CSP includes `script-src 'self' 'unsafe-inline'`, which weakens its protection against injected inline script. It appears to be required by the current asset pipeline. Worth confirming whether a nonce- or hash-based policy is feasible, because CSP is one of the few controls that would blunt a stored-XSS bug elsewhere.

### Part B §12 — Form submission matrix

Automated: `tests/e2e/FormMatrixTest.php` — 32 tests, 184 assertions, **31 Pass / 1 Fail**.

Applied at full depth to the priority forms (control definition, exception raise, risk register). The security checks were swept rather than sampled, because one unguarded field there is a Critical.

| § | Check | Result | Status |
|---|---|---|---|
| **12.C.17** | **Mass assignment — control**: injected `tenant_id` (foreign), `status=Active`, `approved_by`, `approved_at`, `current_version`, `sync_status`, `is_template` | **Every one ignored.** Control created as `Draft` in the caller's own tenant | **Pass** |
| **12.C.17** | **Mass assignment — exception**: injected `status=Verified-Closed`, `verified_by`, `verified_at`, `verification_method`, foreign `tenant_id`, `is_overdue`, `age_days` | **Every one ignored.** Cannot create a pre-closed exception | **Pass** |
| 12.C.17 | Injected primary key `id=999999` | Ignored | **Pass** |
| 12.B.13 | Dropdown values not in the list (`type`, `nature`, `frequency`, `severity`) posted directly | All rejected server-side; no record created | **Pass** |
| 12.A.1 | Empty submission | Every required field flagged | **Pass** |
| 12.A.3 | Whitespace-only title | Treated as empty | **Pass** |
| 12.A.4 | Max length + 1 | Rejected server-side | **Pass** |
| 12.A.7 | Past target closure date; impossible date (`2026-02-31`) | Both rejected | **Pass** |
| 12.A.6 | Numeric: zero, out-of-range, non-numeric, negative | All four rejected | **Pass** |
| 12.A.11 | `O'Brien`, `Adeyemi-Okonkwo`, `Contrôle`, Yorùbá diacritics, emoji, `&` | All six stored and rendered back byte-identical | **Pass** |
| **12.C.14** | **Stored XSS** — 4 payloads, each also carrying a literal `</script>` | Cannot break out of the Inertia page block | **Pass** |
| 12.C.14 | XSS payload through an Excel export | Export generates cleanly | **Pass** |
| **12.C.15** | **SQL injection** — `' OR 1=1--`, `UNION SELECT`, `DROP TABLE`, boolean-blind, in a search filter | 0 rows matched, register unchanged, no `SQLSTATE` leaked | **Pass** |
| **12.C.16** | **CSRF** — verified against the **live** app (`APP_ENV=e2e`) | `POST /controls`, `/exceptions`, `/login` all **419** without a token; webhook routes **403** from their own signature checks | **Pass** |
| 12.C.16 | Exemption list pinned | Exactly `auth/sso/*/acs` and `webhooks/*` | **Pass** |
| **12.D.19/20** | **Double submission** | **Two records created** | **Fail — DEF-018** |
| 12.E.29 | Every submitted value persists exactly (types, multi-line text, booleans) | Exact | **Pass** |
| 12.E.31 | Create writes its audit entry | Written | **Pass** |

**Assessment.** This was the module most likely to yield a Critical, and it did not. **Mass assignment is comprehensively guarded** — no controller in the product uses `$request->all()`; everything flows through `FormRequest::validated()`, which strips any key without a rule. The two most dangerous injections in a GRC platform — planting a record in another tenant, and creating an exception that is already `Verified-Closed` with a forged verifier — are both refused. Server-side validation holds independently of the client, SQL injection is parameterised, and CSRF is enforced.

The one defect is the absence of submission idempotency.

**Three of my own assertions were wrong before this module was correct**, and all three would have reported a **false Critical** on XSS. Recorded because the reasoning matters more than the result:

1. `onerror=alert(1)` — asserted absent from the HTML. But HTML escaping rewrites only `< > " &`, so that substring survives *correct* escaping untouched.
2. `<script` — asserted absent. It matches the page's own Vite asset tags on every page in the application.
3. The whole payload string — asserted absent. It matches the JSON-escaped form, because `\"><img src=x onerror=alert(1)>` contains the payload as a substring.

Only after inspecting the actual rendered container did the real property become clear: Inertia serialises props into the **content** of `<script id="app" type="application/json">`, where the HTML parser recognises exactly one thing — a literal `</script` sequence. `json_encode`'s slash escaping produces `<\/script>`, so no stored value can terminate the block. That is now what the test asserts, with a payload that deliberately contains a closing tag.

### TC-18 — ThirdLine Internal Audit integration (FR-11.1 … FR-11.10) — **exit criterion 7**

Automated: `tests/e2e/ThirdLineIntegrationTest.php` — 19 tests, 108 assertions, **17 Pass / 2 Fail**.

ThirdLine is stubbed with `Http::fake()`, so outbound behaviour is asserted on the request that would have left the building — its URL, headers and body — not on a mock's return value.

| ID | Test | Result | Status |
|---|---|---|---|
| TC-18-01/-02 | Control published with the full contract payload | Envelope (`tenant_id`, `external_ref`, `source_system`, `version_no`, `last_approved_at`) and all 9 mapped attributes present and correct | **Pass** |
| FR-11.5 | External identifier minted on first publish, stable thereafter | `SL-<uuid>`, unchanged across publishes | **Pass** |
| FR-11.6 | Outbound request carries the API key and an idempotency key | Both present; key matches the log row | **Pass** |
| FR-11.6 | Outbound secret never written to the sync log | Absent from payload and error message | **Pass** |
| TC-18-04 | Pull-only tenant does not push | Nothing sent | **Pass** |
| TC-18-04 | Push-only tenant refuses inbound writes | `409`, no record created | **Pass** |
| TC-18-03 | Bidirectional accepts an inbound control | Created, correct tenant, marked `synced_from_thirdline` — **but every attribute except the title replaced by a default** | **Fail — DEF-016** |
| TC-18-06 | Repeat delivery with the same `Idempotency-Key` | `200 duplicate`, no second record | **Pass** |
| TC-18-06 | Re-sync of the same `external_ref` | Updates in place, no duplicate | **Pass** |
| TC-18-05 | Stale ThirdLine version vs newer local, `last_approved_at_wins` | `skipped`; the local version survived | **Pass** |
| TC-18-09 | Malformed payloads (missing `external_ref`, missing title, `control` not an array) | All `422`, no partial write | **Pass** |
| TC-18-07 | Absent key / wrong key / deactivated config | `401` in all three; no write | **Pass** |
| TC-18-08 | ThirdLine returns `503` | Logged `Failed` with the status, payload retained for replay, local record untouched | **Pass** |
| TC-18-08 | Connection refused | Caught, not thrown; recorded | **Pass** |
| — | Publication failure during control approval | Approval still stands — publication never rolls back the business operation | **Pass** |
| FR-11.7 | Failed event replayed after recovery | `Success`, and `replayed_from_id` links to the original | **Pass** |
| FR-11.7 | Replay reuses the original idempotency key | **New UUID issued** | **Fail — DEF-017** |
| TC-18-10 | Both directions logged with payload, outcome, entity type and timestamp | All present | **Pass** |
| — | Sync logs are tenant-scoped | No foreign rows | **Pass** |

**Assessment — exit criterion 7.** The integration is well built on almost every axis the plan names: authentication is sound in all three negative cases, direction configuration is honoured in *both* directions, conflict resolution protects the newer local version, inbound idempotency works, malformed payloads are rejected cleanly, a failed publication never rolls back the business operation it was reporting, and every event lands in the sync log with a replayable payload.

Two defects, on opposite sides of the contract. **DEF-016 is the serious one**: inbound synchronisation silently discards every control attribute except the title. This was localised precisely — `IntegrationService::ingestControl()` maps all fields correctly when handed the full payload, so the loss is entirely in the controller, where `$request->validate()` returns only the keys it has rules for and `control.title` is the only `control.*` rule.

**TC-18-11 (large batch sync) not executed.**

### TC-15 — Executive dashboard (FR-10.1 … FR-10.6) — **exit criterion 6**

Automated: `tests/e2e/DashboardReconciliationTest.php` — 17 tests, 82 assertions, **16 Pass / 1 Fail**.

Every tile was recomputed independently, with raw query-builder counts written against the tables rather than by reusing `DashboardService`'s own expressions, then compared.

| ID | Test | Result | Status |
|---|---|---|---|
| TC-15-01 | Open / closed / pending-verification tiles vs SQL | Exact match | **Pass** |
| TC-15-02 | Each of the four severity buckets vs SQL | Exact match | **Pass** |
| TC-15-02 | Severity buckets sum to the open total | Sum exact — nothing uncounted or double-counted | **Pass** |
| — | Dashboard reports all four BRD severities | Critical/High/Medium/Low | **Pass** |
| TC-15-03 | Overdue tile vs SQL | Exact match | **Pass** |
| TC-15-06 | Tile moves when the underlying record changes | Moved by exactly 1 | **Pass** |
| FR-10.5 | Ageing buckets (0-30/31-60/61-90/90+) sum to the open total | Sum exact — no record falls through | **Pass** |
| FR-10.1 | Unresolved Critical+High tile, and agreement with the severity buckets | Both exact | **Pass** |
| FR-10.3 | Testing tiles + completion rate = completed ÷ total | Exact | **Pass** |
| FR-10.4 | Effectiveness distribution counts each control once | Matches distinct rated controls | **Pass** |
| FR-2.5 | Risk coverage % = (risks − gaps) ÷ risks | Exact | **Pass** |
| TC-15-04 | Unit filter, and severity filter | Both reconcile; filtered ≤ unfiltered | **Pass** |
| TC-15-04 | Tenant scoping | Two tenants produce different dashboards; neither sees the global total | **Pass** |
| TC-15-07 | Open tile drills through to a list with a matching total | Tile = list total | **Pass** |
| TC-15-05 | Zero-data tenant | Clean zeros; no division-by-zero on completion rate or coverage % | **Pass** |
| FR-10.6 | Period / process / owner / risk filters | **Not implemented anywhere** | **Fail — DEF-015** |

### TC-14 — Reporting and export (FR-10.7, FR-10.8, FR-4.4 … FR-4.6)

Automated: `tests/e2e/ReportingTest.php` — 15 tests, 56 assertions, **15 Pass / 0 Fail**.

Excel exports were parsed back out of the streamed bytes with PhpSpreadsheet and their rows counted, rather than treating a `200` as evidence the figures were right.

| ID | Test | Result | Status |
|---|---|---|---|
| TC-14-01/-06 | Exception register, testing summary and board pack render as PDFs | All three, each > 1 KB | **Pass** |
| TC-14-06 | Exceptions / controls / test-instances xlsx open cleanly | All three parse with data rows | **Pass** |
| **TC-14-10** | **Exception export row count vs SQL** | **Exact** | **Pass** |
| **TC-14-10** | **Controls export row count vs SQL** | **Exact** (excludes library templates, correctly) | **Pass** |
| **TC-14-10** | **Test-instance export row count vs SQL** | **Exact** | **Pass** |
| TC-14-03 | Export honours a severity filter | Row count matches the filtered scope | **Pass** |
| TC-14-04 | Exports are tenant-scoped | No foreign record in the bytes | **Pass** |
| TC-14-05 | Spot check report renders with its findings | PDF produced | **Pass** |
| TC-14-07 | Zero-data report | Renders an empty-state PDF, not an error | **Pass** |
| — | Export requires `export reports` | Control Owner and Line Manager both refused | **Pass** |
| — | Another tenant's export contains only their records | Row count matches their tenant exactly | **Pass** |

**Assessment — exit criterion 6.** *"Dashboard and report figures reconcile exactly to source data."* Across 12 dashboard tiles and 3 Excel exports, **every figure reconciled exactly**, including the internal consistency checks (severity buckets summing to the open total, ageing buckets summing to the open total, the Critical/High tile agreeing with the severity donut beside it, and the drill-through list matching the tile it came from). No variance was found, so the plan's High-severity condition for TC-14-10 is not triggered. The criterion fails only on FR-10.6's missing filters, which is a capability gap rather than a figure error.

**Three of my own test errors were corrected during this module**, none of them product behaviour: `streamedContent()` was called on dompdf responses that are not streamed; the board-pack route requires a `sections[]` parameter that my request omitted; and I expected the controls export to contain all 37 controls when it correctly excludes the 24 library templates, exporting the 13 the institution actually operates.

**Not run:** TC-14-02 (configurable report templates/sections — the report designer sits in Tier 2), TC-14-08 (1,000+ record performance), TC-14-09 (scheduled delivery to recipients).

### TC-12 — Escalation engine (FR-8.1 … FR-8.8)

Automated: `tests/e2e/EscalationEngineTest.php` — 11 tests, 59 assertions, **7 Pass / 4 Fail** (all four failures are one defect).

Escalation is a daily 07:00 batch, so every case drives `secondline:run-escalations` through the clock harness rather than waiting.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-12-02 | Critical exception **1 day** overdue | Tier 1 only (tiers 2/3/4 due on days 2/5/10) | **Tiers 1, 2, 3 and 4 all fired** | **Fail — DEF-012** |
| TC-12-03 | Critical exception **6 days** overdue | Tiers 1–3, not tier 4 | Tiers 1–4 | **Fail — DEF-012** |
| — | Executive tier not notified on day one | 0 events | 1 event | **Fail — DEF-012** |
| FR-8.4 | **Low** exception 1 day overdue (ladder says day 14) | Silent | **Escalated** — same day as a Critical | **Fail — DEF-012** |
| TC-12-04 | Exception inside its target date | No escalation | None | **Pass** |
| FR-8.8 | `Remediated` exception | Escalation suspended | Suspended | **Pass** |
| TC-12-06 | Sweep run three times | Each (exception, rule) fires once | No duplicates across three runs | **Pass** |
| TC-12-09 | Change a configured threshold | Behaviour changes, no code change | Threshold 5 → silent; threshold 0 → fires | **Pass** |
| — | Deactivated matrix rule | Does not fire | Did not fire | **Pass** |
| TC-12-05 | Escalation with no resolvable recipient | Recorded, not dropped | Recorded with `delivery_status = Failed` | **Pass** |
| TC-12-08 | Due date "today" in Lagos, 23:30 UTC the day before | Not treated as overdue | Not treated as overdue | **Pass** |

**Assessment.** The mechanics of the engine are sound — deduplication, suspension on remediation, deactivation, configurability, unroutable-recipient handling and timezone correctness all hold. The **ladder itself does not**: `exception_overdue` filters on the `is_overdue` flag and never consults `days_threshold`, so all four tiers and all four severities escalate the moment an exception slips. Every other trigger in the same `match` block does honour its threshold, so the omission is confined to the one branch that carries the BRD's primary escalation path.

**Not executed in this module:** TC-12-01 (immediate owner notification on a failed check, and notification *content* correctness) and TC-12-07 (queue worker down then restored). The latter needs the queued-channel behaviour established first — see `04-coverage-gaps.md`.

### TC-12 / TC-16 — Escalation and notifications (earlier, incidental findings)

Not executed as a planned module. The two defects below were found while verifying that the queue worker was live in Phase 1, which the plan requires precisely because a dead worker masks escalation defects.

| ID | Test | Expected | Actual | Status |
|---|---|---|---|---|
| TC-12-01 (partial) | Escalation delivers on configured channels | In-app **and** email | In-app delivered; **all 3 escalation emails failed permanently** | **Fail — DEF-001** |
| TC-12-01 (partial) | Delivery status logged accurately (FR-8.7) | `Failed` for failed delivery | Recorded **`Sent`** for all three | **Fail — DEF-002** |

Full TC-12 (SLA thresholds, tiered escalation, duplicate prevention, time-zone correctness, worker-down recovery) was **not executed** — it requires clock manipulation.

---

## Test harness — built 2026-08-14 (Track C Step 1)

Fixtures and clock control were built so the blocked modules can execute. No product code was touched.

| Component | Provides | Unblocks |
|---|---|---|
| `E2ETestSeeder` (extended) | Spot checks at all 4 lifecycle states + 6 findings; 3 compensating controls incl. one past end date; 4 evidence records (clean / PII-classified / legal-hold / past-expiry) with real files on disk and matching checksums; **a second tenant** with its own users, unit, control and exception | TC-09, TC-11, TC-13, TC-02-07, TC-New-04 |
| `E2ETestCase` clock helpers | `runCommandAt()`, `at()`, `lagos()`, `makeOverdueByDays()`. Clock restored in a `finally` block so a failing assertion cannot leak a frozen clock | TC-12 in full, TC-10-11/-12, TC-13-08, TC-11-04 |
| `HarnessSelfCheckTest` | 9 tests, 48 assertions — fails loudly if a fixture goes missing, an evidence checksum stops matching its bytes, the second tenant stops being separate, or the clock helpers stop working | The whole suite |

The seeder is idempotent (verified by re-running: counts unchanged). The clock helpers are proven against two different scheduled commands — evidence disposal and the ageing sweep — rather than asserted.

**Suite total: 348 tests, 1,598 assertions, 317 passed, 29 failed, 2 skipped.** All 29 failures are regression guards for the 19 automated-suite defects; DEF-010 accounts for 7, DEF-012 for 4, DEF-019 for 2 and DEF-007 for 2.

## Defects raised

| ID | Severity | Title |
|---|---|---|
| DEF-001 | High | Escalation email fails for every non-exception, non-test escalation subject |
| DEF-002 | High | `delivery_status` records `Sent` for escalations whose delivery failed |
| DEF-004 | Medium | `audit_trails` mutable via the query builder |
| DEF-005 | Medium | Denied authorisation attempts leave no record |
| DEF-007 | High | Deactivating a user does not end their live session |
| DEF-008 | Medium | `exists:users,id` validates existence but not tenancy (46 occurrences) |
| DEF-009 | Medium | Read-only roles can attach evidence to any record in their tenant |
| DEF-010 | Medium | No file-type restriction on evidence upload |
| DEF-011 | Low | Zero-byte file accepted as evidence |
| DEF-012 | High | `exception_overdue` ignores `days_threshold` — all tiers and severities escalate on day one |
| DEF-013 | Medium | Generated test instances carry no assigned tester |
| DEF-014 | Low | Re-rating leaves the superseded approver attached |
| DEF-015 | Medium | FR-10.6: period / process / owner / risk filters not implemented |
| DEF-016 | High | Inbound ThirdLine sync discards every control attribute except the title |
| DEF-017 | Medium | A replayed delivery gets a new idempotency key |
| DEF-018 | Medium | No form submission idempotency — resubmission duplicates |
| DEF-019 | High | No rate limiting on any /api/v1 route |
| DEF-020 | Medium | `.env.example` ships APP_DEBUG=true with no deployment guidance |
| DEF-021 | Low | `X-Powered-By` discloses the PHP version |
| DEF-003 | Low | `verifyAndClose()` does not re-check the exception-owner SoD rule |
| DEF-006 | Low | One un-ageable row aborts the entire nightly ageing sweep, silently |

Full detail in [`02-defect-log.md`](02-defect-log.md).

---

## Test-harness corrections made during the run

Recorded for transparency — three initial failures were **the test harness's fault, not the product's**, and were corrected rather than logged as defects:

1. `APP_ENV=e2e` in `phpunit-e2e.xml` produced `419` (CSRF) on every POST. Laravel skips CSRF only when `APP_ENV=testing`. Corrected; the served application still runs under `APP_ENV=e2e`.
2. PHPUnit 12 no longer reads `@dataProvider` annotations. Converted to `#[DataProvider]` attributes.
3. An audit-trail assertion read a seeder-created row (actor legitimately null, as seeders run unauthenticated) rather than an HTTP-originated one, and used the wrong payload key (`assignee_id` for `user_id`) so validation failed while `assertRedirect()` still passed. Corrected, and `assertSessionHasNoErrors()` added so a silently-rejected payload cannot pass again.

None of these three represent product behaviour.

---

## Product regression suite — baseline

The project's own suite was run in full as a baseline signal.

```
OK (784 tests, 2863 assertions)   —   ./vendor/bin/phpunit, 12m 16s, exit 0
```

**784 tests across 69 files (63 Feature, 6 Unit), all passing.** This is a substantial and genuinely green regression suite, and it is a fair point in the product's favour.

Two qualifications on how much it proves:

1. **It runs on in-memory SQLite** (`phpunit.xml`), so it does not exercise the MySQL `enum` constraints that guard every status column in this schema. A state-machine guarantee evidenced only by this suite is weaker than it appears — see coverage gap ENV-6.
2. **It is green while DEF-001 is live.** The suite includes `NotificationDispatchTest`, yet an escalation email that fails permanently for every risk-appetite and KRI breach passes through it undetected. The gap is that no existing test renders `EscalationNotification::toMail()` for an escalation subject other than an exception or a test instance. A passing suite is therefore not evidence that the escalation path works — which is exactly why the plan requires the queue worker to be observed running (Phase 1.2) rather than assumed.

## Not executed

Every remaining Part B case. See [`04-coverage-gaps.md`](04-coverage-gaps.md) for the itemised list and the reason for each.
