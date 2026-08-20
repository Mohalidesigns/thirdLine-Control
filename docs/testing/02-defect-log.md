# 02 — Defect Log

**Product:** SecondLine — Atheris Control Solution
**Branch / commit:** `phase-17-extended-grc` @ `8d233c7`
**Environment:** local e2e — MariaDB 10.4.28, database `atheris_control_e2e`, `APP_ENV=e2e`, `APP_DEBUG=false`, app on `http://127.0.0.1:8123`
**Status:** open — Phase 2 in progress

## Severity definitions (from the test plan)

- **Critical** — data loss/corruption; unauthorised user performs a restricted action; an exception closed by someone other than the control function; evidence retention or NDPA obligation breached; system unavailable; audit trail missing or falsifiable.
- **High** — core workflow cannot complete; escalation or notification fails to fire; report or dashboard figures wrong; ThirdLine integration drops or duplicates records.
- **Medium** — workaround exists; validation gap that does not corrupt data; incorrect but non-material display.
- **Low** — cosmetic, copy, layout, minor UX friction.

## Summary

| ID | Severity | Module | Title | Status |
|---|---|---|---|---|
| DEF-001 | High | Escalation / Notifications | Escalation email fails for every non-exception, non-test escalation subject | Open |
| DEF-002 | High | Escalation / Audit trail | `delivery_status` records `Sent` for escalations whose delivery failed | Open |
| DEF-003 | Low | Exceptions (SoD) | `verifyAndClose()` does not re-check the exception-owner rule the policy enforces | Open |
| DEF-004 | Medium | Audit trail | `audit_trails` is mutable via the query builder — immutability is model-layer only | Open |
| DEF-005 | Medium | Audit trail | Denied authorisation attempts are not recorded anywhere in the application | Open |
| DEF-006 | Low | Exceptions (ageing) | One bad row aborts the entire nightly ageing sweep, silently | Open |
| DEF-007 | **High** | Auth / session | Deactivating a user does not end their live session — access continues with full role permissions | Open |
| DEF-008 | Medium | Tenancy / validation | `exists:users,id` validates existence but not tenancy — a foreign user can be made responsible for our records | Open |
| DEF-009 | Medium | Evidence | Any authenticated user, including read-only roles, can attach evidence to any record in their tenant | Open |
| DEF-010 | Medium | Evidence | No file-type restriction on evidence upload — `.php`, `.exe`, `.sh`, `.svg`, `.html` and double extensions all accepted | Open |
| DEF-011 | Low | Evidence | A zero-byte file is accepted as evidence | Open |
| DEF-012 | **High** | Escalation | `exception_overdue` ignores `days_threshold` — every tier and every severity escalates at once, on day one | Open |
| DEF-013 | Medium | Control testing | Generated test instances carry no assigned tester — dead ternary in the scheduler | Open |
| DEF-014 | Low | Effectiveness ratings | Re-rating returns a record to Pending Approval but leaves the previous approver attached | Open |
| DEF-015 | Medium | Dashboard / Reporting | Four of the seven filters required by FR-10.6 are implemented nowhere | Open |
| DEF-016 | **High** | ThirdLine integration | Inbound control sync silently discards every attribute except the title | Open |
| DEF-017 | Medium | ThirdLine integration | A replayed delivery is given a new idempotency key, defeating retry de-duplication | Open |
| DEF-018 | Medium | Forms (all) | No submission idempotency — a double-clicked or resubmitted form creates a duplicate record | Open |
| DEF-019 | **High** | API / Security | No rate limiting on any `/api/v1` route — valid or invalid keys can be replayed without limit | Open |
| DEF-020 | Medium | Deployment | `.env.example` ships `APP_DEBUG=true` and no deployment document mentions turning it off | Open |
| DEF-021 | Low | Security headers | `X-Powered-By: PHP/8.4.18` discloses the runtime version on every response | Open |

---

## DEF-001

```
ID:            DEF-001
Title:         [Escalation] Escalation email fails for every escalation subject that is not an exception or a test instance
Severity:      High
Test Case:     TC-12-01, TC-12-02, TC-16-01, TC-16-02
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — queue worker, system-generated
```

**Preconditions**
Seeded database (`php artisan db:seed`), queue worker running against `QUEUE_CONNECTION=database`. The product seed creates three escalation events: one risk-appetite breach and two KRI/metric breaches.

**Steps**
1. `DB_DATABASE=atheris_control_e2e php artisan migrate:fresh --seed --force`
2. `APP_ENV=e2e php artisan queue:work`
3. Inspect `failed_jobs` and `notifications`.

**Expected**
Each escalation is delivered on the channels configured for the matrix rule. Rule channel is `both`, so each recipient receives an in-app notification **and** an email carrying a working deep link to the escalated item.

**Actual**
The in-app (`database`) channel delivers correctly — all three escalations appear in `notifications`. The `mail` channel throws for all three and lands in `failed_jobs`:

```
Illuminate\Routing\Exceptions\UrlGenerationException:
Missing required parameter for [Route: test-instances.show]
[URI: test-instances/{test_instance}] [Missing parameter: test_instance].
```

After the configured retries, no escalation email is ever delivered.

**Root cause**

`app/Notifications/EscalationNotification.php:27-29`

```php
->action('Open SecondLine', url($this->event->exception_id
    ? route('exceptions.show', $this->event->exception_id, false)
    : route('test-instances.show', $this->event->test_instance_id, false)))
```

The deep link is built from a two-way branch, but `escalation_events` carries **seven** possible subjects:

`exception_id`, `test_instance_id`, `campaign_id`, `subject_user_id`, `risk_id`, `metric_id`, `treatment_id`

`EscalationService::dispatch()` populates whichever is relevant. For any escalation raised by `escalateAppetiteBreach()` (risk) or `escalateMetricBreach()` (metric) — and, by inspection of the same code path, for CSA-campaign, attestation-subject and risk-treatment escalations — both `exception_id` and `test_instance_id` are `NULL`. The `else` branch then calls `route('test-instances.show', null)`, which throws.

**Scope of impact**
Confirmed by execution for risk-appetite breaches and KRI/metric breaches. Campaign, subject-user and treatment escalations follow the identical code path and are expected to fail the same way — to be confirmed under TC-12 in Phase 2.

Exception and test-instance escalations — the BRD's core path (FR-8.2) — are **unaffected**, because `exception_id` is populated for those.

**Impact**
Regulatory. FR-8.5 requires escalations delivered by in-app notification **and** email. A Red KRI breach or a risk carried outside appetite escalates to Tier 1 in-app only; the recipient never receives the email. Where a line manager or executive works from email — the normal case for the escalation tiers in FR-8.3 — the escalation is silently invisible to them. Compounded by DEF-002, the escalation register asserts it was delivered.

**Evidence**
`evidence/DEF-001-escalation-mail-failure.txt`

**Suspected cause**
`app/Notifications/EscalationNotification.php:27-29` (do not fix — remediation pass follows sign-off)

---

## DEF-002

```
ID:            DEF-002
Title:         [Escalation/Audit] escalation_events.delivery_status records "Sent" for escalations whose delivery failed
Severity:      High
Test Case:     TC-12-01, TC-12-05, TC-16-01, TC-17-01
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — queue worker, system-generated
```

**Preconditions**
As DEF-001. Three escalation events exist whose `mail` channel failed permanently into `failed_jobs`.

**Steps**
1. Reproduce DEF-001.
2. `select id, delivery_status from escalation_events;`

**Expected**
FR-8.7 requires every escalation logged with *trigger, recipient, timestamp and delivery status*. An escalation whose delivery failed must not be recorded as delivered.

**Actual**

| event | subject | mail outcome | `delivery_status` |
|---|---|---|---|
| 1 | RSK-004 outside risk appetite | failed (`failed_jobs`) | **`Sent`** |
| 2 | KRI-FRAUD Red breach | failed (`failed_jobs`) | **`Sent`** |
| 3 | KRI-DOWNTIME Red breach | failed (`failed_jobs`) | **`Sent`** |

**Root cause**

`app/Services/EscalationService.php:407-413`

```php
try {
    app(NotificationDispatcher::class)->send($recipient, 'escalation.raised', new EscalationNotification($event));
    $event->update(['delivery_status' => 'Sent']);
    ...
} catch (\Throwable $e) {
    $event->update(['delivery_status' => 'Failed']);
}
```

`delivery_status` is set to `Sent` on the success of **dispatch**, not of delivery. Because notification channels are queued, a channel that fails inside the worker fails after this `try` block has already returned, so the `catch` never runs and the status is never corrected. There is no `Notification::failed` / `JobFailed` listener that reconciles the event afterwards.

Note this is not confined to DEF-001's bug. Any transient mail failure — SMTP down, invalid recipient address, provider rejection — produces the same false `Sent`.

**Impact**
Regulatory / audit-trail integrity. This is the register an examiner reads to confirm that a breach was escalated to the right person at the right time. It currently asserts delivery that did not occur, and it does so with no contradicting record anywhere in the application — the failure exists only in Laravel's `failed_jobs` table, which is infrastructure, not audit evidence, and is routinely pruned.

Judged **High** rather than Critical: the audit trail is inaccurate but not falsifiable by a user, and `escalation_events` rows are not themselves editable through the UI. Recommend the Product Owner consider Critical if the escalation register is relied upon as examination evidence — see the note under Exit Criteria 5.

**Evidence**
`evidence/DEF-001-escalation-mail-failure.txt` (same capture — both defects arise from one reproduction)

**Suspected cause**
`app/Services/EscalationService.php:407-413` (do not fix — remediation pass follows sign-off)

---

## DEF-003

```
ID:            DEF-003
Title:         [Exceptions] ExceptionService::verifyAndClose() does not re-check the "verifier is not the exception owner" rule
Severity:      Low
Test Case:     TC-10-09 (service layer), system-map G-01
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Function Head
```

**Preconditions**
An exception at status `Remediated`, whose `owner_id` is the Control Function Head, on a control owned by somebody else, raised manually.

**Steps**
1. `app(ExceptionService::class)->verifyAndClose($exception, $cfh, ['verification_method' => '…'])`

**Expected**
Refused. `ExceptionService::verifyAndClose()` re-checks the segregation-of-duties rules independently of `ExceptionPolicy`, and its own docblock states this explicitly: *"SoD is re-checked here even though ExceptionPolicy already gates the route."*

**Actual**
The exception closes. The service re-checks three of the four closure rules — role, source-tester, control-owner — but not the fourth, `$exception->owner_id !== $verifier->id`, which exists only in `ExceptionPolicy::close()`.

**Reachability — this is not currently exploitable**
`verifyAndClose()` has exactly one caller in application code: `ExceptionController::close()` at `app/Http/Controllers/ExceptionController.php:154`, which is preceded by `$this->authorize('close', $exception)` on line 147. Every HTTP path therefore runs the policy first, and the HTTP negative tests confirm the rule holds end-to-end (`test_control_function_head_cannot_close_an_exception_it_owns` passes).

Rated **Low** on that basis: no unauthorised action is possible today. It is logged because the service's stated design intent is defence in depth, and this rule is the one place that intent is not met — so a future console command, queued listener or second controller calling the service directly would silently lose the guard.

**Impact**
Hardening / defence-in-depth only. No current user-facing or regulatory impact.

**Evidence**
`tests/e2e/ExceptionClosureControlTest.php::test_g01_service_layer_also_refuses_when_verifier_owns_the_exception`
This test is expected to fail until remediated; it is the regression guard.

**Suspected cause**
`app/Services/ExceptionService.php:277-300` — the guard present at `app/Policies/ExceptionPolicy.php:78` (`return $exception->owner_id !== $user->id;`) has no service-layer counterpart. (Do not fix — remediation pass follows sign-off.)

---

## DEF-004

```
ID:            DEF-004
Title:         [Audit] audit_trails rows can be rewritten via the query builder — immutability is enforced only at the model layer
Severity:      Medium
Test Case:     TC-17-02, TC-New-03
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — data layer
```

**Preconditions**
Any existing `audit_trails` row.

**Steps**
1. `AuditTrail::find($id)->update([...])` → correctly throws `LogicException`.
2. `AuditTrail::find($id)->delete()` → correctly throws `LogicException`.
3. `DB::table('audit_trails')->where('id', $id)->update(['action' => 'tampered-by-query-builder'])`

**Expected**
FR-12.4 and NFR-6 require an immutable audit trail. Step 3 should be rejected.

**Actual**
Steps 1 and 2 are correctly refused. **Step 3 succeeds** — the row is silently rewritten. `AuditTrail::booted()` registers `updating`/`deleting` guards, which are Eloquent model events; the query builder does not fire them. There is no database-level protection: no trigger, no append-only enforcement, and the application's MySQL grant holds `UPDATE` and `DELETE` on the table.

**Mitigating findings (verified, not assumed)**
- No HTTP route exposes audit-trail mutation. Asserted by enumerating the full route table for any `POST`/`PUT`/`PATCH`/`DELETE` route whose URI contains "audit" — the set is empty (`test_tc_17_02_no_route_exposes_audit_trail_mutation` passes).
- The audit log UI and its export are correctly restricted to System Administrator and Control Function Head with `view audit log`, and denied to Control Officer, Control Owner, Line Manager and Executive Viewer.

So this is **not** reachable by an application user today. It is rated Medium rather than Critical on that basis.

**Impact**
Regulatory / defence in depth. The BRD's word is "immutable" (FR-12.4) and "no hard deletes … audit trail only" (NFR-6). As implemented, immutability is a convention enforced by one class, not a property of the record. Anyone with application database credentials — which includes the application itself, any future migration, any raw query, and any operator with the deployment `.env` — can rewrite history leaving no trace. For a CBN or NDPC examination this is the difference between an audit trail that is evidence and one that is merely a log.

Recommend the Product Owner consider whether the deployment standard should revoke `UPDATE`/`DELETE` on `audit_trails` from the application grant, or add a database trigger, and record the decision either way.

**Evidence**
`tests/e2e/AuditTrailIntegrityTest.php::test_tc_new_03_query_builder_bypass_of_audit_immutability`
This test restores the original value before asserting, so it never leaves the table dirty.

**Suspected cause**
`app/Models/AuditTrail.php:34-38` — model-layer guards only. (Do not fix.)

---

## DEF-005

```
ID:            DEF-005
Title:         [Audit] Denied authorisation attempts leave no record anywhere in the application
Severity:      Medium
Test Case:     TC-17-05
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Owner (attempting a Control Function Head action)
```

**Preconditions**
An exception at status `Remediated`.

**Steps**
1. Authenticate as `owner1@secondline.test` (Control Owner).
2. `POST /exceptions/{exception}/close` with a valid payload.
3. Confirm the response is `403` and the exception is unchanged.
4. Compare `audit_trails` row count before and after.

**Expected**
TC-17-05: authorisation failures are recorded, not silently dropped. An examiner must be able to answer "did anyone attempt to close this exception outside the control function?".

**Actual**
The request is correctly refused — `403`, no state change — but the row count is **unchanged (2159 → 2159)**. The attempt leaves no record in `audit_trails`, no `exception_activities` entry, and no application log entry. `bootstrap/app.php` registers no handler for `AuthorizationException`, and no global listener records denials.

The refusal itself is correct and is not in question. What is missing is the record of it.

**Scope**
Verified for exception closure. The same absence applies to every policy- and middleware-gated action in the system, since there is no global denial-logging mechanism.

**BRD position — stated precisely**
FR-12.4 enumerates *"every create, update, delete, approval, closure, escalation, export, and evidence access"*. It does **not** name denied attempts. This defect is therefore raised against **TC-17-05 of the test plan**, not against a literal BRD requirement. Whether it is in scope for release is a Product Owner call, and it is flagged here rather than assumed either way.

The argument for treating it as in scope: an attempted breach of the segregation-of-duties control in FR-12.3 is precisely the event a second-line platform exists to surface, and it is the one event the platform currently cannot show.

**Impact**
Regulatory / detective-control gap. Repeated attempts by a control owner to close their own exceptions — the exact behaviour FR-12.3 is designed to prevent — are invisible to the control function and to an examiner.

**Evidence**
`tests/e2e/AuditTrailIntegrityTest.php::test_tc_17_05_authorisation_failures_are_recorded`

**Suspected cause**
No `AuthorizationException` handler in `bootstrap/app.php`; no denial listener in `app/Providers/`. (Do not fix.)

---

## DEF-006

```
ID:            DEF-006
Title:         [Exceptions] A single un-ageable exception aborts the entire nightly ageing sweep, silently
Severity:      Low
Test Case:     TC-10-11 (found while building the clock harness)
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — scheduled command
```

**Preconditions**
Any open exception whose `date_raised` is later than the date on which the sweep runs.

**Steps**
1. Set an open exception's `date_raised` to a date after "today".
2. Run `secondline:refresh-ageing`.

**Expected**
The sweep either skips or clamps the anomalous row, ages every other exception, and surfaces the anomaly.

**Actual**
The command aborts on the first such row:

```
SQLSTATE[22003]: Numeric value out of range: 1264
Out of range value for column 'age_days' at row 1
(SQL: update `control_exceptions` set `age_days` = -145 ... where `id` = 2)
```

`age_days` is `unsignedInteger` (`…150018_create_control_exceptions_table.php:41`). `ExceptionService::refreshAgeing()` computes `$exception->date_raised->diffInDays(now()->startOfDay())`, which is **signed** in Carbon 3, so a future `date_raised` yields a negative value the column rejects. The exception propagates out of `chunkById`, the command fails, and **no exception is aged or flagged overdue on that run** — including every row after the offending one, and every row in every remaining chunk.

Because it is a cron job, the failure is silent to users. The visible symptom is not an error but stale `age_days` and missing `is_overdue` flags — which then suppress the 07:00 escalation sweep that keys off them.

**Reachability — currently unreachable through the application**
`date_raised` is never user-supplied. It is absent from `ExceptionRequest::rules()`, and every one of the eleven creation paths sets it to `now()->toDateString()` (`ExceptionController:72`, `ExceptionService` ×5, plus `IncidentService`, `VendorService`, `AssuranceService`, `MetricService`, `CsaService`, `AssuranceApiController`). A future `date_raised` therefore cannot be produced by any current code path.

Rated **Low** on that basis. It is logged as a robustness gap, not a live fault, because the blast radius if it ever *is* reached is disproportionate to the cause: one anomalous row silently disables overdue detection platform-wide until someone notices.

Plausible future triggers worth guarding against: a data migration or backfill; a bulk import path gaining exception support (`import data` permission already exists); a clock skew between application servers; or a tenant operating ahead of the application's UTC clock — the seeded tenant's timezone is `Africa/Lagos` (UTC+1) while `config/app.php` sets `UTC`, so a "today" in Lagos is already tomorrow relative to the sweep for one hour each day. That last case does not currently produce a negative because `date_raised` is written from the same UTC `now()`, but it is the kind of coupling that breaks quietly if either side changes.

**Impact**
Availability of a detective control. No data corruption; no unauthorised access.

**Evidence**
Observed during construction of the clock harness. `tests/e2e/HarnessSelfCheckTest.php::test_run_command_at_is_observed_by_the_ageing_sweep` documents the constraint and deliberately does **not** re-trigger it — it runs the sweep at a date later than every seeded `date_raised`.

**Suspected cause**
`app/Services/ExceptionService.php:381-390` — unguarded signed diff written to an unsigned column, inside a `chunkById` with no per-row error isolation. (Do not fix.)

---

## DEF-007

```
ID:            DEF-007
Title:         [Auth/Session] Deactivating a user does not terminate their live session — access continues with full role permissions
Severity:      High  (candidate for Critical — see "Severity rationale")
Test Case:     TC-02-03, TC-02-05
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer (any role reproduces)
```

**Preconditions**
An active user with a valid password.

**Steps**
1. `POST /login` as `officer1@secondline.test` with the correct password. Session established.
2. `GET /exceptions` → **200**. Baseline access confirmed.
3. An administrator deactivates the account: `is_active = false`.
4. Re-request `GET /exceptions` **with the same session**.

**Expected**
Access ends. The deactivated user is logged out or refused on the next request.

**Actual**
**200.** The session remains fully valid and the user retains every permission of their role. Access continues until the session expires.

This was executed as a **real login session**, not via `actingAs()`, precisely so the result reflects genuine session behaviour rather than a test affordance.

**Root cause**

`is_active` is evaluated in exactly one place — `app/Http/Requests/Auth/LoginRequest.php:44`:

```php
if (! $this->user()->is_active) {
    Auth::logout();
    throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
}
```

That runs only during `POST /login`. Nothing re-checks it afterwards. Laravel's session guard rehydrates the user through `EloquentUserProvider::retrieveById()`, which does not filter on `is_active`, and there is no middleware in the stack (`AssignRequestId` → `HandleInertiaRequests` → `EnforceMfa` → `SecurityHeaders`) that inspects it.

**Duration of retained access**
`SESSION_LIFETIME=120` minutes, and Laravel refreshes the session on each request. A user who keeps working therefore keeps access **indefinitely**, not for a bounded two hours. There is also no administrative "force logout" — the roles screen offers role changes and deactivation, neither of which invalidates an existing session.

**Severity rationale — stated precisely**
The test plan's role matrix requires only that a deactivated user *"be denied at login and via API token"*. **Both of those pass**: login is correctly refused (`test_tc_01_03_deactivated_user_cannot_authenticate`), and `/api/v1` authenticates against integration config keys rather than user credentials, so no user token path exists. This defect is therefore raised **beyond the plan's literal wording**, on the same basis as DEF-005.

Rated **High** because it is an access-control failure with an entirely ordinary trigger. Recommend the Product Owner consider **Critical**, because deactivation is the primary incident-response control: when an account is compromised, or an employee is dismissed for cause, disabling the account is the action taken — and it currently does nothing to the session already in flight. For a Control Function Head that session can still close exceptions; for a System Administrator it can still administer roles.

Not rated Critical unilaterally because the affected user held legitimate access moments earlier, so this is improper session termination rather than privilege escalation, and no unauthorised party gains access they never had.

**Related observations (not defects)**
- Role changes have the same shape but behave correctly: `syncRoles()` writes through and permission checks re-read on the next request, verified by `test_tc_02_02_role_change_grants_and_revokes_immediately`.
- Both invariants on the roles screen hold: the System Administrator role is immutable, and the last active administrator cannot be demoted.

**Scope — measured, not estimated**
The Tier 2 sweep re-ran this across every page in the application. A deactivated user with a live session reaches **11 pages spanning 9 domains**, including the control library, the test-instance queue, the **exception register**, compensating controls, CSA responses, notifications and both settings screens. Access is not partial or degraded — it is the full set their role already had.

**Impact**
Regulatory / access control. FR-12.8 requires session management aligned to CBN IT standards. An institution cannot currently evidence that revoking a user's access takes effect.

**Evidence**
`tests/e2e/AuthorisationMatrixTest.php::test_tc_02_03_deactivating_a_user_terminates_their_live_session`

**Suspected cause**
`app/Http/Requests/Auth/LoginRequest.php:44` is the only `is_active` check on the user session path. No per-request revalidation exists. (Do not fix.)

---

## DEF-008

```
ID:            DEF-008
Title:         [Tenancy] User-reference validation checks existence but not tenancy — a user from another tenant can be assigned to our records
Severity:      Medium
Test Case:     TC-02-07, TC-New-04
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer
```

**Preconditions**
Two tenants seeded. An open exception belonging to tenant 1.

**Steps**
1. Authenticate as `officer1@secondline.test` (tenant 1).
2. `POST /exceptions/{our-exception}/assign` with `user_id` = the id of `owner@othertenant.test` (**tenant 2**).
3. Re-read the exception.

**Expected**
Rejected. A user from another tenant cannot be made responsible for our exception.

**Actual**
Accepted. `responsible_party_id` is set to the foreign user.

**Root cause**

`app/Http/Controllers/ExceptionController.php:118`

```php
$request->validate(['user_id' => ['required', 'exists:users,id']]);
$this->exceptionService->assign($exception, User::findOrFail($request->integer('user_id')), $request->user());
```

`exists:users,id` proves the row exists; it says nothing about which tenant owns it. `User::findOrFail()` does not narrow it either, because **`App\Models\User` does not use the `BelongsToTenant` trait** — unlike every domain model — so no global scope applies.

**This is a pattern, not one endpoint.** `exists:users,id` appears **46 times** across `app/Http/Requests/`, none of them tenant-scoped: `ExceptionRequest` (`owner_id`, `responsible_party_id`), `PolicyRequest`, `MetricRequest`, `VendorContractRequest`, `ComplaintRequest`, `ImprovementActionRequest`, `InitiativeRequest` and others.

**Blast radius — established by test, not assumed**
It does **not** become a cross-tenant breach. `test_a_cross_tenant_assignment_still_does_not_grant_read_access` confirms that the foreign user still cannot open the record: `ControlException` carries `BelongsToTenant`, so the global scope blocks the read even though `ExceptionPolicy::view()` would otherwise grant sight to the responsible party.

So the tenant scope is the only thing standing between this and a genuine breach — which is exactly the "defence in depth" the README claims for `tenant_id`, working as intended, but with the outer layer missing.

**Impact**
Data integrity and operational. Three consequences:
1. The exception becomes silently unactionable — assigned to someone who can never open it, so remediation stalls with no error anywhere.
2. Another institution's user name renders in our UI as the responsible party, and ours in theirs.
3. Notifications route to a user outside the tenant.

Rated **Medium**: no cross-tenant data access, no unauthorised action on a record, and the branch-per-client deployment model means two tenants rarely share a database. Rated no lower because the same missing check appears 46 times, so it is a systemic gap rather than an oversight in one controller.

**Evidence**
`tests/e2e/TenantIsolationTest.php::test_cannot_assign_our_exception_to_a_user_from_another_tenant`
`tests/e2e/TenantIsolationTest.php::test_a_cross_tenant_assignment_still_does_not_grant_read_access` (passes — bounds the impact)

**Suspected cause**
`app/Http/Controllers/ExceptionController.php:118` and 45 further `exists:users,id` rules; `App\Models\User` lacks `BelongsToTenant`. (Do not fix.)

---

## DEF-009

```
ID:            DEF-009
Title:         [Evidence] Any authenticated user, including read-only roles, can attach evidence to any record in their tenant
Severity:      Medium
Test Case:     TC-02-05 (un-gated routes), TC-13-06
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Executive Viewer, Line Manager
```

**Preconditions**
Any test instance, exception, finding or obligation instance in the caller's tenant.

**Steps**
1. Authenticate as `exec@secondline.test` (Executive Viewer — the read-only board tier).
2. `POST /evidence` with a file, `linked_type=test_instance`, and the id of any test instance.
3. Repeat as `manager@secondline.test` (Line Manager).

**Expected**
Refused. Neither role holds any authoring capability in BRD §4; the Executive Viewer is explicitly the read-only board tier.

**Actual**
**Both succeed.** The evidence row is created and the file stored.

**Root cause**

`app/Http/Controllers/EvidenceController.php:24` declares no role or permission middleware and performs no authorisation check. It validates the shape of the upload thoroughly — file size, `linked_type` against the `LINKABLE` allowlist, mandatory PII declaration, PII categories, classification — but never asks whether the caller may attach evidence at all, nor whether they have any relationship to the record identified by `linked_id`.

The asymmetry is the tell. The same controller's `download()` at line 47 gets it right:

```php
abort_unless(auth()->user()->hasAnyRole([
    'System Administrator', 'Control Function Head', 'Control Officer',
]) || $evidence->uploaded_by === auth()->id(), 403);
```

**Read is gated; write is not.**

**What is correctly enforced**
- Cross-tenant attachment fails — `EvidenceService::store()` checks the linked record exists through a tenant-scoped query (`test_evidence_cannot_be_attached_to_another_tenants_record` passes).
- The upload is audit-logged, since `Evidence` uses the `Auditable` trait, so a planted item is attributable after the fact.

**Impact**
Regulatory. Evidence is the artefact the control function inspects when verifying remediation before closing an exception (FR-5.5), and the record an examiner reads to confirm a control was tested (FR-9.1). Who may place it matters as much as who may read it. As implemented, any authenticated user in the tenant can attach a document to any control test or exception — including the board tier, which under BRD §4 authors nothing.

Rated **Medium** rather than High: the action is audit-logged and attributable, it cannot cross a tenant boundary, and it does not by itself let anyone close or alter an exception. Rated no lower because it is a write capability granted to roles the BRD defines as read-only, on the product's primary evidentiary artefact.

**Evidence**
`tests/e2e/UngatedRouteAuthorisationTest.php::test_read_only_roles_cannot_attach_evidence_to_a_control_test`

**Suspected cause**
`app/Http/Controllers/EvidenceController.php:24` — `store()` has neither middleware nor an authorisation check, unlike `download()` at line 47 and `storePolicy()` at line 101. (Do not fix.)

---

## DEF-010

```
ID:            DEF-010
Title:         [Evidence] No file-type restriction on evidence upload — executables, scripts and script-capable documents are all accepted
Severity:      Medium  (see "Severity rationale" — the plan's Critical condition is NOT met)
Test Case:     TC-13-02
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer (and, per DEF-009, any authenticated role)
```

**Preconditions**
Any test instance, exception, finding or obligation instance in the caller's tenant.

**Steps**
`POST /evidence` with each of the following, a valid `linked_type`/`linked_id`, and `contains_personal_data=false`.

**Expected**
Rejected server-side by content inspection, not extension alone.

**Actual — all seven accepted and stored:**

| Payload | Result |
|---|---|
| `shell.php` | **Accepted** |
| `payload.exe` | **Accepted** |
| `run.sh` | **Accepted** |
| `invoice.pdf.php` (double extension) | **Accepted** |
| `ledger.php` declared as `text/csv` (spoofed MIME) | **Accepted** |
| `logo.svg` (script-capable) | **Accepted** |
| `report.html` | **Accepted** |

**Root cause**

`app/Http/Controllers/EvidenceController.php:26-34`

```php
'file' => ['required', 'file', 'max:20480'],
```

That is the entire file rule. There is no extension allowlist, no MIME allowlist, and no content inspection. `EvidenceService::store()` then records `'mime_type' => $file->getClientMimeType()` — the value the **caller** supplied, never verified against the bytes — so the stored MIME is not a fact any downstream consumer should rely on.

**Severity rationale — the Critical condition was tested, not assumed**

The plan rates this **"Critical if a web-executable file lands in a web-served path"**. It does not, and that was verified rather than inferred (`test_tc_13_02_evidence_disk_is_not_web_served`):

- `EvidenceService::DISK = 'local'`, whose root is `storage_path('app/private')` — outside the document root.
- That disk has **no** `url` configured, so files are not addressable by URL at all.
- Retrieval goes only through `GET /evidence/{id}/download`, which is permission-checked and serves `Content-Disposition: attachment` (verified) — so a stored `.html` or `.svg` cannot execute on the application origin.
- Stored paths are randomised by Laravel's `hashName()` and do not echo the original filename (verified).

Rated **Medium** on the plan's own rubric: a validation gap that does not corrupt data, with the Critical condition absent.

**Recommend the Product Owner consider High**, for two reasons the rubric does not capture:
1. **NFR-4 commits to "OWASP Top 10 remediated."** Unrestricted file upload is a named OWASP risk, and this is the textbook instance of it.
2. **Read DEF-009 together with this one.** Any authenticated user — including the Executive Viewer, the read-only board tier — can place an arbitrary executable into the evidence repository. The compensating controls all establish that *the server* will not execute it; none address a person downloading a file presented to them as audit evidence and opening it. There is no anti-virus or content scanning anywhere in the pipeline.

**Impact**
Security. The evidence repository can be used to store and distribute arbitrary executables within the institution, under the trust label of "control evidence".

**Evidence**
`tests/e2e/EvidenceRetentionTest.php::test_tc_13_02_dangerous_file_types_are_rejected` (7 data sets, all failing)
`tests/e2e/EvidenceRetentionTest.php::test_tc_13_02_evidence_disk_is_not_web_served` (passes — bounds the severity)

**Suspected cause**
`app/Http/Controllers/EvidenceController.php:27`; `app/Services/EvidenceService.php` records the client-supplied MIME. (Do not fix.)

---

## DEF-011

```
ID:            DEF-011
Title:         [Evidence] A zero-byte file is accepted as evidence
Severity:      Low
Test Case:     TC-13-04
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer
```

**Steps**
`POST /evidence` with a 0 KB file.

**Expected**
Rejected gracefully.

**Actual**
Accepted and stored, with `file_size = 0` and a checksum of the empty string.

**Impact**
Evidence completeness. An empty file satisfies any "evidence required" gate while proving nothing — including, potentially, the mandatory-evidence check on test submission (FR-3.3, TC-07-07, not yet executed). The rest of the pipeline treats it as a genuine artefact: it takes a retention class, is queued for disposal on expiry, and appears in the disposal log.

Rated Low: no security or data-integrity consequence, and it is visible to anyone who opens the file. Worth fixing alongside DEF-010, since both live in the same validation rule.

**Evidence**
`tests/e2e/EvidenceRetentionTest.php::test_tc_13_04_zero_byte_file_is_rejected`

**Suspected cause**
`app/Http/Controllers/EvidenceController.php:27` — no `min:1` on the file rule. (Do not fix.)

---

## DEF-012

```
ID:            DEF-012
Title:         [Escalation] exception_overdue ignores days_threshold — the entire tier ladder fires on day one, for every severity
Severity:      High
Test Case:     TC-12-02, TC-12-03
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — scheduled command
```

**Preconditions**
The seeded Critical matrix defines a four-tier ladder for `exception_overdue`:

| tier | threshold | recipient |
|---|---|---|
| 1 | day 0 | Control Owner |
| 2 | day 2 | Line Manager |
| 3 | day 5 | Control Function Head |
| 4 | day 10 | Executive Viewer |

**Steps**
1. Create a Critical exception **one day** past its target closure date, status `In Progress`.
2. Run `secondline:run-escalations`.
3. Read `escalation_events` joined to `escalation_matrices` for that exception.

**Expected**
Tier 1 only. Tier 2 is not due until day 2, tier 3 until day 5, tier 4 until day 10.

**Actual**
**All four tiers fire, on day one.** The Control Owner, the Line Manager, the Control Function Head and the **Executive Viewer** are all escalated to simultaneously, for an exception that has been overdue for a single day.

At six days overdue, tier 4 (day 10) has also already fired.

**Severity pacing is broken in the same way — verified separately.** A **Low**-severity exception one day overdue fires its tier-1 escalation, which the matrix configures for **day 14**. So a Low exception escalates on exactly the same day as a Critical one.

**Root cause**

`app/Services/EscalationService.php:41-43`

```php
'exception_overdue' => $this->escalateExceptions($rule, fn ($q) => $q
    ->where('is_overdue', true)
    ->whereNotIn('status', ['Remediated'])),
```

The constraint filters on the `is_overdue` boolean and **never references `$rule->days_threshold`**. Every other trigger in the same `match` block does:

```php
'exception_unassigned' => … ->whereDate('date_raised', '<=', now()->subDays($rule->days_threshold)),
'exception_inactive'   => … ->where('updated_at',   '<=', now()->subDays($rule->days_threshold)),
'test_overdue'         => … ->whereDate('due_date',  '<=', now()->subDays($rule->days_threshold)),
```

So the omission is confined to one branch, and `exception_overdue` is the branch that carries the BRD's primary escalation path. Every rule with that trigger — 12 of the 30 seeded rules, across all four severities — matches as soon as the nightly ageing sweep flips `is_overdue`.

The per-(exception, rule) deduplication then works *against* recovery: each tier fires once and is thereafter suppressed, so the escalations that should have arrived on days 2, 5 and 10 are already spent and will never arrive at the right moment.

**Impact**
Regulatory, and directly against two **Must** requirements:

- **FR-8.3** (*"Tiered escalation path: Control Owner → Line Manager / Unit Head → Control Function Head → Executive Management → Board"*) — there is no tiering. Every rung fires together.
- **FR-8.4** (*"Configure escalation intervals per severity — Critical escalates faster and further than Low"*) — Critical and Low escalate identically.

Operationally the effect is alarm fatigue at the top of the institution: the Executive Viewer tier receives a notification for every Critical exception on the day it slips, so the signal that was meant to distinguish *"this has been ignored for ten days"* from *"this is one day late"* is gone. An escalation matrix that fires everything at once is, in practice, no escalation matrix.

It also makes the matrix configuration screen misleading: an administrator setting tier 4 to day 10 will see it stored, and it will have no effect.

Rated **High**, not Critical: nothing is lost or exposed, the tier-1 escalation that matters most does fire, and the audit record of each escalation is written. But the escalation model that FR-8.3 and FR-8.4 describe is not the one running.

**Related — the same run confirmed these work correctly**
- `exception_unassigned` honours its threshold, and changing that threshold changes behaviour with no code change (TC-12-09 passes).
- Re-running the sweep does not duplicate (TC-12-06 passes).
- A `Remediated` exception is suspended from escalation (FR-8.8 passes).
- An exception inside its target date does not escalate (TC-12-04 passes).
- A deactivated rule does not fire.
- An escalation with no resolvable recipient is recorded as `Failed`, not dropped (TC-12-05 passes).
- Thresholds do not drift across the Lagos/UTC boundary (TC-12-08 passes).

**Evidence**
`tests/e2e/EscalationEngineTest.php::test_tc_12_02_an_exception_one_day_overdue_escalates_only_to_tier_one`
`tests/e2e/EscalationEngineTest.php::test_tc_12_03_the_ladder_advances_with_time_rather_than_firing_at_once`
`tests/e2e/EscalationEngineTest.php::test_the_executive_tier_is_not_notified_on_day_one`
`tests/e2e/EscalationEngineTest.php::test_fr_8_4_severity_changes_the_pace_of_escalation`

**Suspected cause**
`app/Services/EscalationService.php:41-43` — the `exception_overdue` constraint omits the `days_threshold` clause present in every sibling branch. (Do not fix.)

---

## DEF-013

```
ID:            DEF-013
Title:         [Control testing] Auto-generated test instances carry no assigned tester
Severity:      Medium
Test Case:     TC-07-01, TC-07-02
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — scheduled command
```

**Steps**
1. An Active control with a frequency and an owner.
2. Run `secondline:generate-test-instances` (or call `TestingService::createInstanceForPeriod()`).
3. Read `assigned_tester_id` on the generated instance.

**Expected**
FR-3.4: *"Auto-generate a test instance on the control's defined frequency, **with a due date and an assigned tester**."* The due date is set correctly. The tester should be too.

**Actual**
`assigned_tester_id` is `null` on every generated instance.

**Root cause**

`app/Services/TestingService.php`, in `createInstanceForPeriod()`:

```php
'assigned_tester_id' => $control->owner_id ? null : null,
```

Both branches of the ternary evaluate to `null`. This is not a subtle bug — it reads as an assignment rule that was sketched and never completed, and it is unreachable by any input.

**Impact**
Workflow. Every scheduled test lands unassigned, so it appears in nobody's queue until somebody picks it up manually. TC-07-02 (*"appears in that tester's queue only"*) has no subject. It also weakens the overdue-test escalation: `escalateOverdueTests()` falls back to `$instance->tester ?? $instance->control?->owner`, so the escalation lands on the control owner rather than the tester who was supposed to do the work — which for FR-8.3's tiering is the wrong first rung.

The workflow is recoverable — `TestingService::start()` assigns the instance to whoever begins it — so this is Medium rather than High. But nothing prompts anyone to begin.

**Evidence**
`tests/e2e/ControlTestingTest.php::test_fr_3_4_generated_instances_carry_an_assigned_tester`

**Suspected cause**
`app/Services/TestingService.php` — `createInstanceForPeriod()`, the `assigned_tester_id` ternary. (Do not fix.)

---

## DEF-014

```
ID:            DEF-014
Title:         [Effectiveness] Re-rating returns a record to Pending Approval but leaves the superseded approver attached
Severity:      Low
Test Case:     TC-08-05, FR-7.6
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer / Control Function Head
```

**Steps**
1. Rate a test instance Effective / Effective as the Control Officer.
2. Approve it as the Control Function Head — status becomes `Published`.
3. Re-rate the same instance Ineffective / Ineffective.
4. Read the row.

**Expected**
The record returns for approval with no approver attached — nobody has approved the new values.

**Actual**
`status` correctly resets to `Pending Approval` and the new ratings are stored, but **`approved_by` and `approved_at` are unchanged**. The row reads *"Ineffective, Pending Approval, approved by the Control Function Head at [the earlier timestamp]"* — and that person approved *Effective*, not this.

**Root cause**

`TestingService::rate()` uses `EffectivenessRating::updateOrCreate()` and writes `'status' => 'Pending Approval'` without clearing `approved_by` / `approved_at`.

**Impact**
Audit clarity. The status field is correct, so no unapproved rating is treated as published, and the approval gate still requires a second person before it can publish again. But a report or export that renders `approved_by` without also checking `status` will attribute the new rating to someone who never saw it.

Rated **Low**: the controlling field is right, and the mis-stated one is a display/attribution issue rather than a control failure. It is logged because attribution is the whole point of FR-7.6.

**The surrounding behaviour is correct**, and was verified in the same run: a new rating is never self-published; a rating cannot be approved by whoever proposed it; approval by a second person publishes it and recomputes residual risk; re-rating updates rather than duplicating; and each control holds at most one rating per period.

**Evidence**
`tests/e2e/EffectivenessRatingTest.php::test_re_rating_clears_the_previous_approver`

**Suspected cause**
`app/Services/TestingService.php` — `rate()`, the `updateOrCreate()` payload. (Do not fix.)

---

## DEF-015

```
ID:            DEF-015
Title:         [Dashboard/Reporting] Four of the seven filters required by FR-10.6 are implemented nowhere
Severity:      Medium
Test Case:     TC-14-03, TC-15-04
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Function Head
```

**Expected**
FR-10.6 (**Must**): *"Filters: period, unit, process, control owner, severity, status and risk."*

**Actual**

| Filter | Dashboard | Exception register | Export |
|---|---|---|---|
| unit | yes | yes | — |
| severity | yes | yes | yes |
| status | no | yes | yes |
| **period** | **no** | **no** | **no** |
| **process** | **no** | **no** | **no** |
| **control owner** | **no** | **no** | **no** |
| **risk** | **no** | **no** | **no** |

`DashboardService::metrics()` reads only `unit_id` and `severity`. `ExceptionController::index()` adds `status`, a free-text search and an `overdue` toggle. Nothing anywhere accepts period, process, control owner or risk.

**Not a silent-failure defect — verified.** The dashboard UI offers a unit selector only, and the exception register offers only the filters it implements. So an unsupported filter cannot be selected through the UI; passing one as a query parameter is simply ignored. That is the better of the two failure modes, and it was checked rather than assumed.

**Impact**
Reporting capability, against a **Must** requirement. The absent filters are the ones a control function actually reports on:

- **Period** is the most consequential. FR-10.3 requires testing completion *"by period"* and FR-10.5 requires exception ageing analysis; without a period filter the dashboard can only ever show all-time figures, so a quarterly board pack cannot be scoped to its quarter from the UI.
- **Control owner** is how a line manager would see their own people's outstanding items.
- **Process** and **risk** are the two axes an RCSA-driven institution reports along.

The workaround is the Excel export plus a pivot table, which is why this is Medium rather than High — but it moves the analysis off the platform, which is the opposite of what FR-10.6 is for.

**Note on scope.** Phase 13's dashboard builder and report designer may offer richer filtering; neither was tested (they sit in Tier 2 under scope decision D-3). This defect is raised against the FR-10.1 fixed dashboard and the exception register, which are the surfaces the BRD describes.

**Evidence**
`tests/e2e/DashboardReconciliationTest.php::test_fr_10_6_period_process_owner_and_risk_filters_are_not_implemented`

**Suspected cause**
`app/Services/DashboardService.php::metrics()` — the `when()` chain reads two keys. (Do not fix.)

---

## DEF-016

```
ID:            DEF-016
Title:         [ThirdLine] Inbound control synchronisation silently discards every attribute except the title
Severity:      High
Test Case:     TC-18-02, TC-18-03
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — machine caller on /api/v1
```

**Preconditions**
A tenant with a ThirdLine config at `sync_direction = Bidirectional`.

**Steps**
`POST /api/v1/controls` with a valid `X-Api-Key` and a fully populated control:

```json
{
  "external_ref": "TL-E2E-0001",
  "last_approved_at": "2026-08-14T…",
  "control": {
    "title": "Inbound control from ThirdLine",
    "description": "Defined in ThirdLine and synchronised inbound.",
    "objective": "Prove FR-11.3.",
    "type": "Detective",
    "nature": "Automated",
    "frequency": "Quarterly",
    "is_key_control": true
  }
}
```

**Expected**
The control is created with the attributes ThirdLine sent.

**Actual**
The control is created — `201`, correct `external_ref`, correct tenant, correctly marked `synced_from_thirdline` — but **every attribute except `title` is discarded and replaced by a default**:

| Sent | Stored |
|---|---|
| type `Detective` | **`Preventive`** |
| nature `Automated` | **`Manual`** |
| frequency `Quarterly` | **`Monthly`** |
| is_key_control `true` | **`false`** |
| description *(supplied)* | **`null`** |
| objective *(supplied)* | **`null`** |

No error, no warning, no note in the sync log — which records `Success`.

**Root cause — and where it is *not***

`IntegrationService::ingestControl()` is **correct**. Called directly with the full payload it maps every field faithfully; that was verified separately before this defect was raised.

The loss is in `app/Http/Controllers/Api/IntegrationApiController.php::upsertControl()`:

```php
$validated = $request->validate([
    'external_ref'  => ['required', 'string', 'max:255'],
    'last_approved_at' => ['nullable', 'date'],
    'control'       => ['required', 'array'],
    'control.title' => ['required', 'string', 'max:255'],
]);

return response()->json($this->integrationService->ingestControl($config, $validated), 201);
```

`$request->validate()` returns **only the keys named in the rules**. `control.title` is the only `control.*` rule, so `$validated['control']` is `['title' => …]` and everything else is stripped before the service ever sees it. `ingestControl()` then applies its `?? 'Preventive'` / `?? 'Manual'` / `?? 'Monthly'` fallbacks, which exist for genuinely sparse payloads and here mask a complete one.

**Impact**
Regulatory and data integrity, against a **Must** requirement.

FR-11.3 requires that controls defined in ThirdLine *"can flow into the Control Solution"*, and FR-11.5 that a control is *"never re-keyed manually across products"*. As implemented, every control synced in from ThirdLine arrives mis-classified: a detective, automated, quarterly key control becomes a preventive, manual, monthly non-key control with no description or objective.

The consequences run downstream, because those fields drive behaviour rather than just display:
- `frequency` drives the testing calendar — a quarterly control would be scheduled monthly.
- `type` and `is_key_control` drive reporting, the effectiveness distribution and board-pack classification.
- The absent `objective` is the field an examiner reads to understand what the control is *for*.

And it is silent. The sync log says `Success`, so nothing surfaces the loss to either side. An institution would discover it only by comparing the two systems by hand — which is precisely what FR-11.5 exists to make unnecessary.

Rated **High**: no record is dropped or duplicated, so it is not the plan's named Critical case, but the substance of every inbound record is corrupted on a **Must** integration path.

**Evidence**
`tests/e2e/ThirdLineIntegrationTest.php::test_tc_18_03_bidirectional_accepts_an_inbound_control`

**Suspected cause**
`app/Http/Controllers/Api/IntegrationApiController.php::upsertControl()` — passes `$validated` rather than the full `control` array. (Do not fix.)

---

## DEF-017

```
ID:            DEF-017
Title:         [ThirdLine] A replayed delivery is issued a new idempotency key, defeating retry de-duplication
Severity:      Medium
Test Case:     TC-18-06, TC-18-08
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — outbound publication
```

**Steps**
1. Publish a control while ThirdLine returns `503`. The sync log records `Failed` with idempotency key *K1*.
2. `IntegrationService::replay($failed)`.
3. Compare the replay's idempotency key with *K1*.

**Expected**
The same key. A replay is a **redelivery of the same event**, and the idempotency key is what lets the receiver recognise it as such.

**Actual**
A brand-new UUID. `IntegrationService::send()` unconditionally sets `'idempotency_key' => (string) Str::uuid()`, and `replay()` routes through `send()`.

**Why this matters — from the product's own contract**

SecondLine's *inbound* endpoint de-duplicates on exactly this header:

```php
$prior = IntegrationSyncLog::…->where('idempotency_key', $idempotencyKey)
    ->where('direction', 'inbound')->where('status', 'Success')->exists();
if ($prior) { return response()->json(['action' => 'duplicate'], 200); }
```

So the platform states, in its own code, that a repeated delivery is identified by a stable key. Its outbound side does not provide one across retries.

The dangerous case is not a clean `503` — it is a **timeout**, where the first delivery may in fact have been processed and only the response was lost. On replay ThirdLine sees an unfamiliar key and processes it as a new event. FR-11.7 requires replay of failed events; TC-18-06 requires that re-sync not create duplicates.

**Mitigating factors** — the outbound `external_ref` is stable across retries (verified), so a well-built receiver matching on `external_ref` would upsert rather than duplicate. Rated **Medium** on that basis: whether a duplicate actually occurs depends on ThirdLine's implementation, not SecondLine's. But SecondLine is sending the signal that says "this is a different event", and it is the side that knows it is not.

**Everything else about replay works**, verified in the same run: a failed event retains its payload and is replayable, the replay succeeds when the endpoint recovers, and `replayed_from_id` correctly links the retry to its original.

**Evidence**
`tests/e2e/ThirdLineIntegrationTest.php::test_a_replay_reuses_the_original_idempotency_key`

**Suspected cause**
`app/Services/IntegrationService.php::send()` — mints a UUID on every call; `replay()` does not pass the original key through. (Do not fix.)

---

## DEF-018

```
ID:            DEF-018
Title:         [Forms] No submission idempotency — a resubmitted form creates a duplicate record
Severity:      Medium
Test Case:     §12.D.19, §12.D.20
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     Control Officer
```

**Steps**
1. `POST /exceptions` with a valid payload.
2. `POST /exceptions` again with the **identical** payload.

**Expected**
§12.D.19: *"Double-click submit / rapid double POST → exactly one record created (idempotency)."*
§12.D.20: *"Submit, then browser back, then resubmit → no duplicate."*

**Actual**
Two exceptions are created, each with its own generated reference.

**Root cause**
There is no idempotency mechanism on any web form. No submission token, no dedupe on a natural key, no unique constraint that would catch an identical re-post. `ExceptionController::store()` calls `ControlException::create()` unconditionally.

**Scope**
Demonstrated on the exception register because that is the highest-consequence form. The same pattern applies to every create endpoint in the product — none carries a submission token or a uniqueness guard on the fields a user actually fills in.

**Impact**
Data quality in the register that matters most. A duplicate exception is not an inert row: it takes its own reference, its own target closure date, its own escalation ladder, and it must be separately remediated, verified and closed by the control function. It also inflates every figure that reconciles off the register — the open count, the severity buckets, the ageing analysis and the board pack, all of which were verified exact in TC-15 and would remain arithmetically exact while overstating the institution's true position.

The realistic triggers are ordinary: a double-clicked submit on a slow connection, a browser back-and-resubmit, or a client retry after a timeout where the first request in fact succeeded.

Rated **Medium**: no data is lost or corrupted, the duplicate is visible and can be closed, and CSRF tokens are single-use per session rather than per-form so they do not incidentally prevent this. Rated no lower because the correction path runs through the control function's verification queue — removing a spurious exception costs the same second-line effort as closing a real one.

**Note.** The API surface *does* get this right: `POST /api/v1/controls` honours an `Idempotency-Key` header and returns `{"action":"duplicate"}` on a repeat (verified in TC-18-06). The machine-facing contract has the protection the human-facing forms lack.

**Evidence**
`tests/e2e/FormMatrixTest.php::test_12_d_19_a_double_submitted_form_creates_two_records`

**Suspected cause**
`app/Http/Controllers/ExceptionController.php::store()` and every equivalent `store()` in the product — no submission idempotency anywhere. (Do not fix.)

---

## DEF-019

```
ID:            DEF-019
Title:         [API/Security] No rate limiting on any /api/v1 route
Severity:      High
Test Case:     §9 (status codes incl. 429), §10 (rate limiting)
Environment:   local e2e, MariaDB 10.4.28, branch phase-17-extended-grc @ 8d233c7
Role Used:     n/a — machine caller
```

**Steps**
1. Issue 70 consecutive `GET /api/v1/controls` with a **valid** key.
2. Issue 70 consecutive requests with **invalid** keys (`guess-0` … `guess-69`).

**Expected**
A `429 Too Many Requests` appears in both sequences. §9 lists 429 among the status codes the contract must produce, and §10 requires rate limiting.

**Actual**
- 70/70 valid-key requests answered `200`. No throttling.
- 70/70 invalid-key attempts answered `401`. No throttling.

**Root cause**

`routes/api.php` applies exactly one middleware to the whole `v1` group:

```php
Route::prefix('v1')->middleware('integration.auth')->group(function () { … });
```

There is no `throttle` anywhere on the group or on any individual route. By contrast, several *web* routes do carry throttling (`throttle:10,1`, `throttle:20,1` on data-source test and capture), so the mechanism is understood and used elsewhere — it is simply absent from the integration surface.

**Impact**
Security, on two distinct axes:

1. **Unthrottled key guessing.** `AuthenticateIntegration` loads every active config and compares the presented key against each hash. An attacker can probe the key space at full request rate with no lockout, no delay and — per **DEF-005** — no record of the attempts anywhere in the application.
2. **Unthrottled use of a valid key.** A leaked or stolen key can be replayed without limit. `/api/v1/controls`, `/exceptions` and `/test-results` each return up to 500 records per call, so an attacker with a key can extract the tenant's entire control library, exception register and testing history as fast as the server will answer.

Rated **High**, and it compounds with two other open defects: DEF-005 means the failed attempts leave no trace, and the endpoints return bulk data rather than single records.

**Note on scope.** This is the machine surface. Interactive login *is* throttled — eight consecutive failed logins produced a lockout message, verified in the same run (FR-12.8).

**Evidence**
`tests/e2e/ApiContractTest.php::test_the_api_is_rate_limited`
`tests/e2e/ApiContractTest.php::test_repeated_authentication_failures_are_rate_limited`

**Suspected cause**
`routes/api.php:7` — the `v1` group declares no `throttle` middleware. (Do not fix.)

---

## DEF-020

```
ID:            DEF-020
Title:         [Deployment] .env.example ships APP_DEBUG=true and no deployment document says to turn it off
Severity:      Medium
Test Case:     §10 (verbose errors disabled, debug mode off)
Environment:   repository state at 8d233c7
```

**Expected**
An operator following the documented setup ends up with verbose errors disabled in production.

**Actual**
`.env.example` ships `APP_ENV=local` and `APP_DEBUG=true`. A search of `docs/deployment/` and `SECURITY.md` finds **no mention of `APP_DEBUG` at all**.

The `local` default is the framework norm and is fine in itself. The gap is that nothing in the repository tells an operator to change it, and `composer setup` copies this file verbatim as the first step of installation.

**Impact**
Security. With `APP_DEBUG=true`, any unhandled exception serves a full Ignition stack trace — file paths, source excerpts, SQL with bound values, and environment variables — to whoever triggered it. On a platform holding a bank's control failures and exception register, that is a disclosure of both the application internals and the data in flight.

Rated **Medium**: it is a documentation and default-hardening gap rather than a fault in running code, and a competent deployment would set `APP_ENV=production`. Rated no lower because the repository's own setup path leads to the wrong state and nothing corrects it.

**Verified separately:** the running e2e application (`APP_DEBUG=false`) correctly serves a plain "Not Found" page with no stack trace, and `/vendor`, `/storage`, `/.env` and `/.git/config` all return `404` — see `evidence/S10-security-live-verification.txt`.

**Evidence**
`tests/e2e/SecurityBaselineTest.php::test_the_shipped_environment_template_does_not_default_to_debug_in_production`

**Suspected cause**
`.env.example:4`; absence of guidance in `docs/deployment/`. (Do not fix.)

---

## DEF-021

```
ID:            DEF-021
Title:         [Security headers] X-Powered-By discloses the PHP runtime version on every response
Severity:      Low
Test Case:     §10 (security headers)
Environment:   local e2e, http://127.0.0.1:8123
```

**Actual**
Every response carries `X-Powered-By: PHP/8.4.18`.

**Impact**
Information disclosure. It tells an attacker precisely which runtime and patch level to look up CVEs against, with no benefit to any client. Removed by `expose_php = Off` in `php.ini`, or by unsetting the header in `SecurityHeaders`.

Rated **Low**: no data exposure and no direct exploitability.

**The rest of the header posture is correct**, verified live on the running application: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy` restricting camera/microphone/geolocation, and a Content-Security-Policy with `default-src 'self'`, `frame-ancestors 'none'`, `base-uri 'self'` and `form-action 'self'`. The session cookie is `httponly; samesite=lax`; the `XSRF-TOKEN` cookie is correctly readable by JavaScript. HSTS is set conditionally on `$request->secure()`, which is right — it was not exercised because this environment is HTTP.

**One observation for the Product Owner, not raised as a defect:** the CSP includes `script-src 'self' 'unsafe-inline'` in production. That weakens the CSP's protection against injected inline script. It appears to be required by the current asset pipeline; worth confirming whether a nonce- or hash-based policy is feasible, since CSP is one of the few controls that would blunt a stored-XSS bug elsewhere in the product.

**Evidence**
`evidence/S10-security-live-verification.txt`

---

*Log continues as Phase 2 executes.*
